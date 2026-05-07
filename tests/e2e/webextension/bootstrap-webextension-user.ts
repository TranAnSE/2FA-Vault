import { request as playwrightRequest } from '@playwright/test';
import { webExtensionTestData } from './popup-test-data.fixture';

async function getCsrfToken(context: Awaited<ReturnType<typeof playwrightRequest.newContext>>): Promise<string> {
  const state = await context.storageState();
  const csrfCookie = state.cookies.find(cookie => cookie.name === 'XSRF-TOKEN')?.value;

  if (!csrfCookie) {
    throw new Error('XSRF-TOKEN cookie is missing');
  }

  return decodeURIComponent(csrfCookie);
}

export async function ensureWebExtensionEncryptedUserReady(): Promise<{ pat: string }> {
  const context = await playwrightRequest.newContext({
    baseURL: webExtensionTestData.extensionHostUrl,
    extraHTTPHeaders: {
      'X-Requested-With': 'XMLHttpRequest',
    },
  });

  try {
    await context.get('/refresh-csrf', { failOnStatusCode: false });
    let csrfToken = await getCsrfToken(context);

    const loginResponse = await context.post('/user/login', {
      data: {
        email: webExtensionTestData.user.email,
        password: webExtensionTestData.user.password,
      },
      headers: {
        'X-XSRF-TOKEN': csrfToken,
      },
      failOnStatusCode: false,
    });

    if (!loginResponse.ok()) {
      const loginBody = await loginResponse.text().catch(() => '<unavailable>');
      throw new Error(`Login failed for extension test user: ${loginResponse.status()} body=${loginBody.slice(0, 300)}`);
    }

    let tokenResponse = await context.post('/oauth/personal-access-tokens', {
      data: {
        name: `webext-e2e-${Date.now()}`,
      },
      headers: {
        'X-XSRF-TOKEN': csrfToken,
      },
      failOnStatusCode: false,
    });

    if (tokenResponse.status() === 419) {
      await context.get('/refresh-csrf', { failOnStatusCode: false });
      csrfToken = await getCsrfToken(context);
      tokenResponse = await context.post('/oauth/personal-access-tokens', {
        data: {
          name: `webext-e2e-${Date.now()}-retry`,
        },
        headers: {
          'X-XSRF-TOKEN': csrfToken,
        },
        failOnStatusCode: false,
      });
    }

    if (!tokenResponse.ok()) {
      const tokenBody = await tokenResponse.text().catch(() => '<unavailable>');
      throw new Error(`Failed to create PAT: ${tokenResponse.status()} body=${tokenBody.slice(0, 300)}`);
    }

    const tokenBody = await tokenResponse.json() as { accessToken?: string };
    if (!tokenBody.accessToken) {
      throw new Error('PAT response is missing accessToken');
    }

    const preferenceUpdates: Array<[string, unknown]> = [
      ['formatPassword', true],
      ['formatPasswordBy', 3],
      ['getOtpOnRequest', true],
      ['showNextOtp', false],
      ['showOtpAsDot', false],
      ['revealDottedOTP', false],
    ];

    for (const [key, value] of preferenceUpdates) {
      const preferenceResponse = await context.put(`/api/v1/user/preferences/${key}`, {
        data: { value },
        headers: {
          Authorization: `Bearer ${tokenBody.accessToken}`,
        },
        failOnStatusCode: false,
      });

      if (!preferenceResponse.ok()) {
        const body = await preferenceResponse.text().catch(() => '<unavailable>');
        throw new Error(`Failed to set webextension test preference ${key}: ${preferenceResponse.status()} body=${body.slice(0, 300)}`);
      }
    }

    return { pat: tokenBody.accessToken };
  } finally {
    await context.dispose();
  }
}
