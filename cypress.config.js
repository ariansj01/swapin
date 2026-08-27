const { defineConfig } = require('cypress');

module.exports = defineConfig({
  projectId: 'swaapin-e2e',
  viewportWidth: 1280,
  viewportHeight: 800,
  defaultCommandTimeout: 10000,
  requestTimeout: 60000,
  responseTimeout: 60000,
  pageLoadTimeout: 120000,
  video: true,
  videoCompression: 32,
  screenshotOnRunFailure: true,
  trashAssetsBeforeRuns: true,
  numTestsKeptInMemory: 5,
  experimentalMemoryManagement: true,
  retries: {
    runMode: 1,
    openMode: 0,
  },
  env: {
    baseUrl: 'http://localhost/swaapin',
    apiBaseUrl: 'http://localhost/swaapin/api',
    testUserPhone: '09999999999',
    testAdminEmail: 'admin@swaapin.ir',
    testAdminPassword: 'Admin@123456',
    testOTPCode: '123456',
  },
  e2e: {
    chromeWebSecurity: false,
    setupNodeEvents(on, config) {
      on('task', {
        log(message) {
          console.log(message);
          return null;
        },
        logTable(data) {
          console.table(data);
          return null;
        },
      });
      return config;
    },
    baseUrl: 'http://localhost/swaapin',
    specPattern: 'cypress/e2e/**/*.cy.{js,jsx,ts,tsx}',
    supportFile: 'cypress/support/e2e.js',
    fixturesFolder: 'cypress/fixtures',
    screenshotsFolder: 'cypress/screenshots',
    videosFolder: 'cypress/videos',
    downloadsFolder: 'cypress/downloads',
    experimentalRunAllSpecs: true,
  },
});
