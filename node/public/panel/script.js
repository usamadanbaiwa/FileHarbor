'use strict';

let csrfToken = '';

function getElement(id) {
    return document.getElementById(id);
}

function showMessage(message) {
    getElement('status').textContent = message;
}

function readForm(form) {
    return Object.fromEntries(new FormData(form));
}

function showSection(section) {
    for (const id of ['setup', 'login', 'admin']) {
        getElement(id).hidden = id !== section;
    }
}

async function request(route, method = 'GET', data) {
    const options = {
        method: method,
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        credentials: 'same-origin'
    };

    if (data !== undefined) {
        options.body = JSON.stringify(data);
    }

    const response = await fetch('/panel-api/' + route, options);
    const result = await response.json();

    if (!response.ok) {
        let message = 'Request failed.';

        if (result.error && result.error.message) {
            message = result.error.message;
        }

        throw new Error(message);
    }

    return result.data;
}

function addCheckbox(container, name, checked) {
    const label = document.createElement('label');
    const input = document.createElement('input');

    input.type = 'checkbox';
    input.value = name;
    input.checked = checked;

    label.append(input, document.createTextNode(' ' + name));
    container.append(label);

    return input;
}

function selectedPermissions(container) {
    const checkedInputs = container.querySelectorAll('input:checked');
    const permissions = [];

    for (const input of checkedInputs) {
        permissions.push(input.value);
    }

    return permissions;
}

function displayKey(key) {
    const keyBox = getElement('key');

    keyBox.hidden = false;
    keyBox.textContent = 'Copy this API key now. It will not be shown again.\n' + key;
}

function clearKey() {
    const keyBox = getElement('key');

    keyBox.hidden = true;
    keyBox.textContent = '';
}

function addButton(container, label, handler) {
    const button = document.createElement('button');

    button.type = 'button';
    button.textContent = label;

    button.onclick = async function onClick() {
        button.disabled = true;

        try {
            await handler();
        } catch (error) {
            showMessage(error.message);
        } finally {
            button.disabled = false;
        }
    };

    container.append(button);
}

function renderAccount(account, availablePermissions) {
    const card = document.createElement('article');
    const heading = document.createElement('h3');
    const accountId = document.createElement('p');
    const nameInput = document.createElement('input');
    const permissionsBox = document.createElement('fieldset');
    const enabledBox = document.createElement('div');

    heading.textContent = account.name;
    accountId.textContent = 'ID / public prefix: ' + account.id;

    nameInput.value = account.name;
    nameInput.maxLength = 80;
    nameInput.setAttribute('aria-label', 'Account name');

    for (const permission of availablePermissions) {
        const checked = account.permissions.includes(permission);

        addCheckbox(permissionsBox, permission, checked);
    }

    const enabledInput = addCheckbox(enabledBox, 'Account enabled', account.enabled);

    card.append(heading, accountId, nameInput, permissionsBox, enabledBox);

    addButton(card, 'Save account', async function saveAccount() {
        await request('accounts/' + account.id, 'PATCH', {
            name: nameInput.value,
            permissions: selectedPermissions(permissionsBox),
            enabled: enabledInput.checked
        });

        showMessage('Account updated.');
        await loadAccounts();
    });

    addButton(card, 'Rotate API key', async function rotateKey() {
        if (!confirm('Replace the current key immediately?')) {
            return;
        }

        const result = await request('accounts/' + account.id + '/rotate', 'POST', {});

        displayKey(result.api_key);
        showMessage('Previous key revoked.');
    });

    addButton(card, 'Remove account', async function removeAccount() {
        const confirmed = confirm(
            'Revoke access and remove this account? Files will remain on disk.'
        );

        if (!confirmed) {
            return;
        }

        await request('accounts/' + account.id, 'DELETE');

        showMessage('Account removed; files retained.');
        await loadAccounts();
    });

    return card;
}

async function loadAccounts() {
    const result = await request('accounts');
    const accountsContainer = getElement('accounts');
    const permissionsContainer = getElement('new-permissions');

    accountsContainer.replaceChildren();
    permissionsContainer.replaceChildren();

    for (const permission of result.permissions) {
        addCheckbox(permissionsContainer, permission, false);
    }

    for (const account of result.accounts) {
        const card = renderAccount(account, result.permissions);

        accountsContainer.append(card);
    }

    showSection('admin');
}

function bindForm(id, handler) {
    const form = getElement(id);

    form.onsubmit = async function onSubmit(event) {
        event.preventDefault();

        const button = form.querySelector('button');

        button.disabled = true;

        try {
            await handler(form);
        } catch (error) {
            showMessage(error.message);
        } finally {
            button.disabled = false;
        }
    };
}

async function submitSetup(form) {
    await request('setup', 'POST', readForm(form));

    form.reset();
    showSection('login');
    showMessage('Setup complete. Log in.');
}

async function submitLogin(form) {
    const result = await request('login', 'POST', readForm(form));

    csrfToken = result.csrf_token;

    form.reset();
    await loadAccounts();
    showMessage('Logged in.');
}

async function submitAccount(form) {
    const input = readForm(form);
    const permissions = selectedPermissions(getElement('new-permissions'));

    const result = await request('accounts', 'POST', {
        name: input.name,
        permissions: permissions
    });

    displayKey(result.api_key);
    form.reset();
    await loadAccounts();
    showMessage('Account created.');
}

async function submitCredentials(form) {
    await request('credentials', 'POST', readForm(form));

    csrfToken = '';

    form.reset();
    clearKey();
    showSection('login');
    showMessage('Credentials updated. Log in again.');
}

async function logout() {
    try {
        await request('logout', 'POST', {});

        csrfToken = '';

        clearKey();
        getElement('accounts').replaceChildren();
        showSection('login');
        showMessage('Logged out.');
    } catch (error) {
        showMessage(error.message);
    }
}

async function initializePanel() {
    try {
        const status = await request('status');

        if (status.setup_required) {
            showSection('setup');
            return;
        }

        try {
            const session = await request('session');

            csrfToken = session.csrf_token;

            await loadAccounts();
        } catch {
            showSection('login');
        }
    } catch (error) {
        showMessage(error.message);
    }
}

bindForm('setup-form', submitSetup);
bindForm('login-form', submitLogin);
bindForm('account-form', submitAccount);
bindForm('credentials-form', submitCredentials);

getElement('logout').onclick = logout;

initializePanel();
