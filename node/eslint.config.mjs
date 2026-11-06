export default [
    {
        ignores: [
            '**/node_modules/**',
            '**/.state/**',
            '**/storage/**',
            '**/var/**',
            '**/vendor/**'
        ]
    },
    {
        files: ['**/*.js', '**/*.mjs'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module'
        },
        rules: {
            curly: ['error', 'all'],
            'max-statements-per-line': ['error', { max: 1 }],
            'arrow-body-style': ['error', 'always'],
            'no-ternary': 'error',
            'no-sequences': 'error',
            'no-restricted-syntax': [
                'error',
                {
                    selector: 'ChainExpression',
                    message: 'Use an explicit check instead of optional chaining.'
                },
                {
                    selector: 'LogicalExpression[operator="??"]',
                    message: 'Assign a default value with an explicit if block.'
                },
                {
                    selector: 'AssignmentExpression[operator="??="]',
                    message: 'Assign a default value with an explicit if block.'
                },
                {
                    selector: 'AssignmentExpression[operator="||="]',
                    message: 'Use an explicit if block before assigning.'
                },
                {
                    selector: 'AssignmentExpression[operator="&&="]',
                    message: 'Use an explicit if block before assigning.'
                }
            ]
        }
    }
];
