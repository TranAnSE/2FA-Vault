export const webExtensionTestData = {
  extensionHostUrl: 'http://127.0.0.1:8001',
  // Current extension unlock flow uses one password for PAT decryption and vault E2EE unlock.
  extensionPassword: 'MasterPass123!',
  masterPassword: 'MasterPass123!',
  user: {
    email: 'e2eencrypted@2fauth.app',
    password: 'password',
  },
  account: {
    service: 'VaultDrive',
    account: 'secure@vault.test',
  },
  hotpAccount: {
    service: 'VaultHOTP',
    account: 'hotp@vault.test',
  },
};
