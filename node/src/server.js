import { createApp } from './app.js';
import { loadConfig } from './config/environment.js';

async function startServer() {
    const config = loadConfig();
    const service = await createApp(config);

    const server = service.app.listen(config.port, config.host, function onListening() {
        console.log(
            'FileHarbor listening on ' +
                config.host +
                ':' +
                config.port +
                '; panel: ' +
                config.origin +
                '/panel/'
        );
    });

    server.requestTimeout = 120000;
    server.headersTimeout = 30000;

    server.on('error', function onServerError(error) {
        const code = error.code || 'LISTEN_ERROR';

        console.error('Server failed: ' + code);

        service.close();
        process.exitCode = 1;
    });

    let stopping = false;

    function stopServer() {
        if (stopping) {
            return;
        }

        stopping = true;

        // Stop accepting requests, but give in-flight uploads a short time to finish.
        const deadline = setTimeout(function closeRemainingConnections() {
            server.closeAllConnections();
        }, 10000);

        deadline.unref();

        server.close(function onClosed() {
            clearTimeout(deadline);
            service.close();
        });
    }

    process.once('SIGTERM', stopServer);
    process.once('SIGINT', stopServer);
}

try {
    await startServer();
} catch (error) {
    if (error.code === 'CONFIG_ERROR') {
        console.error(error.message);
    } else {
        console.error(
            'FileHarbor could not initialize. Check configuration and filesystem permissions.'
        );
    }

    process.exitCode = 1;
}
