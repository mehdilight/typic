<?php

declare(strict_types=1);

namespace Typic\Explorer;

class AltairRenderer implements ExplorerInterface
{
    public const CDN_BASE = 'https://cdn.jsdelivr.net/npm/altair-static@8.5.11/build/dist/';

    /**
     * @param array{
     *     title?: string,
     *     endpoint?: string,
     *     subscriptionEndpoint?: string,
     *     initialSubscriptionEndpoint?: string,
     *     initialDocument?: string,
     *     initialVariables?: array<string, mixed>|string,
     *     initialHeaders?: array<string, string>,
     * } $options
     */
    public function render(string $endpoint = '/graphql', array $options = []): string
    {
        $title = htmlspecialchars((string) ($options['title'] ?? 'Typic Altair GraphQL Client'), ENT_QUOTES, 'UTF-8');

        $altairOptions = ['initialName' => $options['title'] ?? 'Typic'];

        if (isset($options['initialDocument'])) {
            $altairOptions['initialQuery'] = (string) $options['initialDocument'];
        }
        if (isset($options['initialVariables'])) {
            $altairOptions['initialVariables'] = is_string($options['initialVariables'])
                ? $options['initialVariables']
                : (string) json_encode($options['initialVariables'], JSON_PRETTY_PRINT);
        }
        if (isset($options['initialHeaders'])) {
            $altairOptions['initialHeaders'] = $options['initialHeaders'];
        }

        $subEndpoint = $options['subscriptionEndpoint'] ?? ($options['initialSubscriptionEndpoint'] ?? null);

        $flags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR;
        $altairOptionsJson = json_encode((object) $altairOptions, $flags);
        $endpointJson = json_encode($endpoint, $flags);
        $subEndpointJson = json_encode($subEndpoint, $flags);
        $cdnBase = self::CDN_BASE;

        return <<<HTML
<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <title>{$title}</title>
    <base href="{$cdnBase}" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link rel="icon" type="image/x-icon" href="favicon.ico" />
    <link href="styles.css" rel="stylesheet" />
    <style>
        body { margin: 0; overflow: hidden; background-color: #1a1e29; }
        #typic-status { display: flex; align-items: center; justify-content: center; height: 100vh; padding: 0 16px;
            color: #fff; font-family: sans-serif; text-align: center; white-space: pre-line; }
    </style>
</head>
<body>
    <app-root>
        <div id="typic-status">Loading Altair GraphQL Client...</div>
    </app-root>

    <script>
        // Shown on the page as well as in the console, since a blank loading screen says nothing
        window.typicAltairFailed = function (message, error) {
            console.error('[Typic] ' + message, error || '');
            var status = document.getElementById('typic-status');
            if (status) {
                status.style.color = '#ff8a80';
                status.textContent = message;
            }
        };
    </script>
    <script type="module" src="polyfills.js" onerror="typicAltairFailed('Could not load Altair from ' + this.src)"></script>
    <script type="module" src="main.js" onerror="typicAltairFailed('Could not load Altair from ' + this.src)"></script>
    <script type="module">
        // <base> points at the CDN, so relative URLs must be made absolute against this page
        function absolute(url, protocol) {
            if (/^[a-z]+:\/\//i.test(url)) {
                return url;
            }
            var path = url.startsWith('/') ? url : '/' + url;
            return (protocol || window.location.protocol) + '//' + window.location.host + path;
        }

        var options = {$altairOptionsJson};
        options.endpointURL = absolute({$endpointJson});

        var subscriptionEndpoint = {$subEndpointJson};
        if (subscriptionEndpoint) {
            options.subscriptionsEndpoint = absolute(subscriptionEndpoint, window.location.protocol === 'https:' ? 'wss:' : 'ws:');
            // Altair defaults to the legacy subscriptions-transport-ws protocol
            options.initialSubscriptionRequestHandlerId = 'graphql-ws';
        }

        if (!window.AltairGraphQL) {
            typicAltairFailed('Altair did not start: AltairGraphQL is not defined.');
        } else {
            try {
                window.AltairGraphQL.init(options);
            } catch (error) {
                typicAltairFailed('Altair failed to start: ' + error.message, error);
            }
        }
    </script>
</body>
</html>
HTML;
    }
}
