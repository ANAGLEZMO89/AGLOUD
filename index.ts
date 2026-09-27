import { config as loadEnv } from 'dotenv';
import {
  createHiggsfieldClient,
  AuthenticationError,
  CredentialsMissedError,
  NotEnoughCreditsError,
  ValidationError,
  BadInputError,
  TimeoutError,
  APIError,
} from '@higgsfield/client/v2';

// Load HF_CREDENTIALS (key-id:key-secret) from .env.local at runtime. Never log it.
loadEnv({ path: '.env.local', quiet: true });

const MODEL = 'bytedance/seedance-2.5/text-to-video';

async function main(): Promise<number> {
  const credentials = process.env.HF_CREDENTIALS;
  if (!credentials || !credentials.includes(':')) {
    console.error('HF_CREDENTIALS is missing or not in key-id:key-secret format. Set it in .env.local.');
    return 1;
  }

  const client = createHiggsfieldClient({
    credentials,
    // Video generation can take longer than the SDK's 5-minute default.
    maxPollTime: 15 * 60 * 1000,
  });

  console.log(`Submitting ${MODEL} request...`);
  const response = await client.subscribe(MODEL, {
    input: {
      prompt: 'A cinematic scene at sunset',
      duration: 5,
      resolution: '720p',
      aspect_ratio: '16:9',
    },
    withPolling: true,
  });

  // The SDK types don't list 'canceled', but the API can return it.
  const status = response.status as string;
  console.log(`Request ${response.request_id} finished with status: ${status}`);

  switch (status) {
    case 'completed': {
      const url = response.video?.url;
      if (!url) {
        console.error('Request completed but no video URL was returned.');
        return 1;
      }
      console.log(`Video URL: ${url}`);
      return 0;
    }
    case 'nsfw':
      console.error('Request was rejected by content moderation (credits refunded).');
      return 1;
    case 'failed':
      console.error('Generation failed (credits refunded).');
      return 1;
    case 'canceled':
      console.error('Request was canceled.');
      return 1;
    default:
      console.error(`Unexpected status: ${status}`);
      return 1;
  }
}

main()
  .then((code) => process.exit(code))
  .catch((error: unknown) => {
    if (error instanceof AuthenticationError || error instanceof CredentialsMissedError) {
      console.error('Authentication failed: check HF_CREDENTIALS in .env.local.');
    } else if (error instanceof NotEnoughCreditsError) {
      // The SDK maps every HTTP 403 to NotEnoughCreditsError, including a proxy/firewall rejection.
      console.error('HTTP 403: not enough credits, access denied, or blocked by a network proxy.');
    } else if (error instanceof ValidationError || error instanceof BadInputError) {
      console.error('Invalid input:', JSON.stringify(error.details ?? error.message));
    } else if (error instanceof TimeoutError) {
      console.error('Timed out waiting for the result (the request may have been canceled or is still running).');
    } else if (error instanceof APIError) {
      console.error(`API error ${error.statusCode ?? ''}: ${error.message}`);
    } else if (error instanceof Error) {
      // Print only the message: axios errors can carry request headers (incl. Authorization).
      console.error(`Unexpected error: ${error.message}`);
    } else {
      console.error('Unexpected error.');
    }
    process.exit(1);
  });
