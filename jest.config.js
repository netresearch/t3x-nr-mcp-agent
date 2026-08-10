export default {
    testEnvironment: 'jest-environment-jsdom',
    transform: {},
    testMatch: ['**/Tests/JavaScript/**/*.test.js'],
    moduleNameMapper: {
        // Map importmap names to vendored files so Jest can resolve them
        '^marked$': '<rootDir>/Resources/Public/JavaScript/Vendor/marked.esm.js',
        '^dompurify$': '<rootDir>/Resources/Public/JavaScript/Vendor/dompurify.esm.js',
        // `lit` comes from the TYPO3 backend importmap at runtime. Mapping the
        // bare specifiers at the npm package lets the components render under
        // jsdom, so their behaviour can be asserted rather than grepped.
        '^lit$': '<rootDir>/node_modules/lit/index.js',
        '^lit/(.*)$': '<rootDir>/node_modules/lit/$1',
        // Stub TYPO3 native modules not available in Jest
        '^@typo3/core/lit-helper\\.js$': '<rootDir>/Tests/JavaScript/__mocks__/@typo3/core/lit-helper.js',
    },
};
