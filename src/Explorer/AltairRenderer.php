<?php

declare(strict_types=1);

namespace Typic\Explorer;

class AltairRenderer implements ExplorerInterface
{
    public const CDN_BASE = 'https://cdn.jsdelivr.net/npm/altair-static@8/build/dist/';

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
        $endpointUrl = json_encode($endpoint, JSON_UNESCAPED_SLASHES);

        $subEndpoint = $options['subscriptionEndpoint']
            ?? ($options['initialSubscriptionEndpoint'] ?? null);
        $subEndpointJson = $subEndpoint !== null
            ? json_encode((string) $subEndpoint, JSON_UNESCAPED_SLASHES)
            : 'null';

        $initialQuery = $options['initialDocument'] ?? null;
        $initialQueryJson = $initialQuery !== null
            ? json_encode((string) $initialQuery, JSON_UNESCAPED_SLASHES)
            : 'null';

        $initialVariables = $options['initialVariables'] ?? null;
        $initialVariablesJson = $initialVariables !== null
            ? (is_string($initialVariables) ? json_encode($initialVariables) : json_encode(json_encode($initialVariables, JSON_PRETTY_PRINT)))
            : 'null';

        $initialHeaders = $options['initialHeaders'] ?? null;
        $initialHeadersJson = $initialHeaders !== null
            ? json_encode($initialHeaders)
            : 'null';

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
        body {
            margin: 0;
            overflow: hidden;
            background-color: #1a1e29;
        }
    </style>
</head>
<body>
    <app-root>
        <div style="display:flex;align-items:center;justify-content:center;height:100vh;color:#fff;font-family:sans-serif;">
            Loading Altair GraphQL Client...
        </div>
    </app-root>

    <script>
        (function() {
            var rawEndpoint = {$endpointUrl};
            var endpoint = rawEndpoint;
            if (rawEndpoint.startsWith('/')) {
                endpoint = window.location.origin + rawEndpoint;
            } else if (!rawEndpoint.startsWith('http://') && !rawEndpoint.startsWith('https://')) {
                endpoint = window.location.origin + '/' + rawEndpoint;
            }

            var subEndpoint = {$subEndpointJson};
            var initialQuery = {$initialQueryJson};
            var initialVariables = {$initialVariablesJson};
            var initialHeaders = {$initialHeadersJson};

            var initialOptions = {
                endpointURL: endpoint,
            };

            if (subEndpoint) {
                var wsUrl = subEndpoint;
                if (wsUrl.startsWith('/')) {
                    var wsProto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
                    wsUrl = wsProto + '//' + window.location.host + wsUrl;
                }
                initialOptions.subscriptionsEndpoint = wsUrl;
                initialOptions.subscriptionsProvider = 'graphql-ws';
            }

            if (initialQuery) {
                initialOptions.initialQuery = initialQuery;
            }
            if (initialVariables) {
                initialOptions.initialVariables = initialVariables;
            }
            if (initialHeaders) {
                initialOptions.initialHeaders = initialHeaders;
            }

            window.__ALTAIR_INITIAL_OPTIONS__ = initialOptions;
        })();
    </script>
    <script type="module" src="polyfills.js"></script>
    <script type="module" src="main.js"></script>
</body>
</html>
HTML;
    }
}
