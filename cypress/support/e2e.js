import './commands';

Cypress.on('uncaught:exception', (err, runnable) => {
  if (err.message.includes('google is not defined')) {
    return false;
  }
  if (err.message.includes('ResizeObserver')) {
    return false;
  }
  return true;
});

beforeEach(() => {
  cy.window().then((win) => {
    win.sessionStorage.clear();
  });
});

afterEach(() => {
  cy.clearCookies();
  cy.clearLocalStorage();
});
