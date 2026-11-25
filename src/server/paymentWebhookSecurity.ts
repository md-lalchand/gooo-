export function resolvePaymentWebhookSecret(
  integrationSecret: string | null | undefined,
  environmentSecret: string | null | undefined,
  nodeEnv: string | undefined,
  developmentSecret: string | null | undefined
): string {
  const integration = String(integrationSecret ?? '').trim();
  if (integration) return integration;

  const environment = String(environmentSecret ?? '').trim();
  if (environment) return environment;

  if (nodeEnv === 'production') {
    throw new Error(
      '[SECURITY] Configure a dedicated PAYMENT_WEBHOOK_SECRET or gateway webhook secret before accepting production webhooks.'
    );
  }

  const development = String(developmentSecret ?? '').trim();
  if (!development) {
    throw new Error('[SECURITY] No payment webhook signing secret is configured.');
  }

  return development;
}
