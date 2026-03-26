export default {
    testEnvironment: 'jest-environment-jsdom',
    transform: {},
    testMatch: ['**/Tests/JavaScript/**/*.test.js'],
    moduleNameMapper: {
        // Map importmap names to vendored files so Jest can resolve them
        '^marked$': '<rootDir>/Resources/Public/JavaScript/Vendor/marked.esm.js',
        '^dompurify$': '<rootDir>/Resources/Public/JavaScript/Vendor/dompurify.esm.js',
        // Stub TYPO3 native modules not available in Jest
        '^@typo3/core/lit-helper\\.js$': '<rootDir>/Tests/JavaScript/__mocks__/@typo3/core/lit-helper.js',
    },
};
