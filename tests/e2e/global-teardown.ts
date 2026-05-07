import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DB_PATH = path.resolve(__dirname, '../..', 'database/database_e2e.sqlite');

/**
 * E2E global teardown - cleans up the test database.
 */
export default async function globalTeardown() {
  for (let attempt = 1; attempt <= 5; attempt += 1) {
    if (!fs.existsSync(DB_PATH)) {
      break;
    }

    try {
      fs.unlinkSync(DB_PATH);
      break;
    } catch (error) {
      if (attempt === 5) {
        console.warn(`[E2E] Could not delete test database: ${error instanceof Error ? error.message : String(error)}`);
        break;
      }

      await new Promise(resolve => setTimeout(resolve, 250));
    }
  }

  console.log('[E2E] Teardown complete.');
}
