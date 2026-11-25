export type GeneratedQuestion = {
  prompt: string;
  options: [string, string, string, string];
  correctIndex: number;
  explanation: string;
  marks: number;
};

export function normalizeGeneratedQuestions(value: unknown, expectedCount: number): GeneratedQuestion[] | null {
  if (!value || typeof value !== 'object' || !Array.isArray((value as { items?: unknown }).items)) {
    return null;
  }

  const items = (value as { items: unknown[] }).items;
  if (!Number.isInteger(expectedCount) || expectedCount < 1 || items.length !== expectedCount) {
    return null;
  }

  const normalized: GeneratedQuestion[] = [];
  for (const item of items) {
    if (!item || typeof item !== 'object') return null;
    const candidate = item as Record<string, unknown>;
    const prompt = typeof candidate.prompt === 'string' ? candidate.prompt.trim() : '';
    const explanation = typeof candidate.explanation === 'string' ? candidate.explanation.trim() : '';
    const options = candidate.options;
    const correctIndex = candidate.correctIndex;
    const marks = candidate.marks;

    if (
      !prompt || prompt.length > 1000 ||
      !explanation || explanation.length > 2000 ||
      !Array.isArray(options) || options.length !== 4 ||
      !options.every((option) => typeof option === 'string' && option.trim().length > 0 && option.length <= 500) ||
      !Number.isInteger(correctIndex) || (correctIndex as number) < 0 || (correctIndex as number) > 3 ||
      !Number.isInteger(marks) || (marks as number) < 1 || (marks as number) > 100
    ) {
      return null;
    }

    normalized.push({
      prompt,
      options: options.map((option) => (option as string).trim()) as [string, string, string, string],
      correctIndex: correctIndex as number,
      explanation,
      marks: marks as number,
    });
  }

  return normalized;
}
