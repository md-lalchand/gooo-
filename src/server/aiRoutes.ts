import express, { type Request, type Response } from 'express';
import type { AiAgentRole, UserRole } from '../types';
import { consumePublicRateLimit } from './publicRedaction';
import { getClientIp } from './security';
import { getDb, recordAuditLog, saveDb } from './store';

const AGENT_INSTRUCTIONS: Record<AiAgentRole, string> = {
  student:
    'You are an AI Student Tutor for Aloms English Center. Teach English Grammar, Vocabulary, Pronunciation, and Spoken English clearly with Bengali + English explanations and practice examples.',
  teacher:
    'You are an AI Teacher Copilot for Aloms English Center. Help educators design HSC/SSC/IELTS lesson plans, board-standard MCQ questions, rubrics, and written feedback.',
  admin:
    'You are an AI Admin Strategist for Aloms English Center. Analyze admissions, branch performance, fee collection, and academic KPIs concisely.',
  support:
    'You are an AI Student Support Desk for Aloms English Center. Answer questions on enrollment, bKash/Nagad/Rocket/Card checkout, coupons, branches, and exam rules.',
  learning:
    'You are an AI Learning Advisor for Aloms English Center. Build personalized weekly study plans using student exam scores, course modules, and course progress.',
};

type ProviderResponse = {
  choices?: Array<{ message?: { content?: string | Array<{ type?: string; text?: string }> } }>;
};

async function requestAiCompletion(systemPrompt: string, userPrompt: string, jsonMode = false): Promise<string | null> {
  const apiUrl = process.env.AI_API_URL?.trim();
  const apiKey = process.env.AI_API_KEY?.trim();
  const model = process.env.AI_MODEL?.trim();
  if (!apiUrl || !apiKey || !model) return null;

  const response = await fetch(apiUrl, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${apiKey}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      model,
      messages: [
        { role: 'system', content: systemPrompt },
        { role: 'user', content: userPrompt },
      ],
      ...(jsonMode ? { response_format: { type: 'json_object' } } : {}),
    }),
    signal: AbortSignal.timeout(30_000),
  });

  if (!response.ok) {
    throw new Error(`Configured AI provider returned HTTP ${response.status}.`);
  }

  const result = (await response.json()) as ProviderResponse;
  const content = result.choices?.[0]?.message?.content;
  if (typeof content === 'string') return content.trim();
  if (Array.isArray(content)) return content.map((part) => part.text ?? '').join('').trim();
  return null;
}

export function createAiRouter(getAuthUser: (req: Request) => { id: string; name: string; role: UserRole } | null) {
  const router = express.Router();

  router.post('/chat', async (req: Request, res: Response) => {
    const agentRole = (['student', 'teacher', 'admin', 'support', 'learning'].includes(
      String(req.body?.agent)
    )
      ? req.body.agent
      : 'support') as AiAgentRole;
    const message = String(req.body?.message ?? '').trim().slice(0, 1500);
    if (!message) {
      res.status(400).json({ error: 'Please enter a message for the AI Agent.' });
      return;
    }

    const authUser = getAuthUser(req);
    if (agentRole === 'admin' && authUser?.role !== 'admin') {
      res.status(403).json({ error: 'Main Admin authorization is required for AI Admin Strategist.' });
      return;
    }
    if (agentRole === 'teacher' && authUser?.role !== 'admin' && authUser?.role !== 'teacher') {
      res.status(403).json({ error: 'Teacher or Admin authorization is required for AI Teacher Copilot.' });
      return;
    }
    if (agentRole === 'learning' && !authUser) {
      res.status(401).json({ error: 'Please sign in to access personalized AI Study Advisor.' });
      return;
    }
    if (!authUser) {
      const rateCheck = consumePublicRateLimit(`ai-guest:${getClientIp(req)}`, 20, 5 * 60 * 1000);
      if (!rateCheck.allowed) {
        res.setHeader('Retry-After', String(rateCheck.retryAfterSeconds));
        res.status(429).json({
          error: `Guest AI chat rate limit reached. Try again in ${rateCheck.retryAfterSeconds}s or sign in.`,
        });
        return;
      }
    }

    const db = getDb();
    const contextSummary =
      agentRole === 'admin' && authUser?.role === 'admin'
        ? `Branches: ${db.branches.length}, Active Courses: ${db.courses.length}, Students/Users: ${db.users.length}, Enrollments: ${db.enrollments.length}, Exam Attempts: ${db.examAttempts.length}.`
        : `Active Courses: ${db.courses.map((c) => `${c.title} (৳${c.price})`).slice(0, 5).join('; ')}. Branches: Mirpur DOHS, Uttara Sector 7, Chattogram GEC.`;

    try {
      const reply = await requestAiCompletion(
        AGENT_INSTRUCTIONS[agentRole],
        `Platform Context: ${contextSummary}\n\nUser Question: ${message}`
      );
      if (reply === null) {
        res.status(503).json({ error: 'AI provider is not configured. Set AI_API_URL, AI_API_KEY, and AI_MODEL in the server environment.' });
        return;
      }
      if (authUser) {
        recordAuditLog(db, authUser, 'AI_AGENT_QUERY', `agent:${agentRole}`, message.slice(0, 80));
        saveDb(db);
      }
      res.json({ agent: agentRole, reply: reply || 'No response generated.' });
    } catch {
      res.status(502).json({ error: 'AI request failed. Check the configured AI provider and try again.' });
    }
  });

  router.post('/generate-questions', async (req: Request, res: Response) => {
    const authUser = getAuthUser(req);
    if (!authUser || (authUser.role !== 'admin' && authUser.role !== 'teacher')) {
      res.status(403).json({ error: 'Teacher or Admin role required for AI question generation.' });
      return;
    }

    const subject = String(req.body?.subject ?? 'HSC English 2nd Paper').trim().slice(0, 160);
    const chapter = String(req.body?.chapter ?? 'Right Form of Verbs').trim().slice(0, 160);
    const count = Math.min(10, Math.max(1, Number(req.body?.count) || 3));

    try {
      const generated = await requestAiCompletion(
        'Generate educational assessment content. Return only valid JSON with an items array. Each item must contain prompt, options (exactly four strings), correctIndex (0-3), explanation, and marks (positive integer).',
        `Generate ${count} high-quality MCQ questions for Bangladesh HSC/SSC/IELTS students. Subject: "${subject}". Chapter: "${chapter}". Return exactly ${count} items.`,
        true
      );
      if (generated === null) {
        res.status(503).json({ error: 'AI provider is not configured. Set AI_API_URL, AI_API_KEY, and AI_MODEL in the server environment.' });
        return;
      }
      const parsed = JSON.parse(generated) as { items?: unknown };
      if (!Array.isArray(parsed.items)) {
        res.status(502).json({ error: 'AI provider returned an invalid question payload.' });
        return;
      }
      res.json({ items: parsed.items.slice(0, count) });
    } catch {
      res.status(502).json({ error: 'AI question generation failed. Check the configured AI provider response.' });
    }
  });

  return router;
}
