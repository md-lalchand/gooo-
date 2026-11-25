import assert from 'node:assert/strict';
import test from 'node:test';
import { normalizeGeneratedQuestions } from './aiValidation';

const validQuestion = {
  prompt: 'Choose the correct verb.',
  options: ['go', 'goes', 'going', 'gone'],
  correctIndex: 1,
  explanation: 'The singular subject takes goes.',
  marks: 1,
};

test('accepts and normalizes an exact valid question set', () => {
  assert.deepEqual(normalizeGeneratedQuestions({ items: [validQuestion] }, 1), [{
    ...validQuestion,
    prompt: validQuestion.prompt,
    explanation: validQuestion.explanation,
  }]);
});

test('rejects missing or unexpected question counts', () => {
  assert.equal(normalizeGeneratedQuestions({ items: [] }, 1), null);
  assert.equal(normalizeGeneratedQuestions({ items: [validQuestion, validQuestion] }, 1), null);
});

test('rejects malformed options and answer indexes', () => {
  assert.equal(normalizeGeneratedQuestions({ items: [{ ...validQuestion, options: ['one', 'two'] }] }, 1), null);
  assert.equal(normalizeGeneratedQuestions({ items: [{ ...validQuestion, correctIndex: 4 }] }, 1), null);
});

test('rejects unsafe or unbounded marks and oversized prompt text', () => {
  assert.equal(normalizeGeneratedQuestions({ items: [{ ...validQuestion, marks: 0 }] }, 1), null);
  assert.equal(normalizeGeneratedQuestions({ items: [{ ...validQuestion, marks: 101 }] }, 1), null);
  assert.equal(normalizeGeneratedQuestions({ items: [{ ...validQuestion, prompt: 'x'.repeat(1001) }] }, 1), null);
});
