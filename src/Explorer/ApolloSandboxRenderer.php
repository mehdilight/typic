<?php

declare(strict_types=1);

namespace Typic\Explorer;

class ApolloSandboxRenderer implements ExplorerInterface
{
    public const CDN_URL = 'https://embeddable-sandbox.cdn.apollographql.com/_latest/embeddable-sandbox.umd.production.min.js';

    /**
     * @param array{
     *     title?: string,
     *     endpoint?: string,
     *     includeCookies?: bool,
     *     initialDocument?: string,
     *     initialVariables?: array<string, mixed>,
     *     initialHeaders?: array<string, string>,
     *     theme?: 'dark'|'light',
     *     subscriptionEndpoint?: string,
     *     initialSubscriptionEndpoint?: string,
     * } $options
     */
    public function render(string $endpoint = '/graphql', array $options = []): string
    {
        $title = htmlspecialchars((string) ($options['title'] ?? 'Typic Apollo Sandbox'), ENT_QUOTES, 'UTF-8');
        $endpointUrl = json_encode($endpoint, JSON_UNESCAPED_SLASHES);
        $includeCookies = isset($options['includeCookies']) && $options['includeCookies'] ? 'true' : 'false';
        $cdnUrl = self::CDN_URL;

        $initialState = [];
        if (isset($options['initialDocument'])) {
            $initialState['document'] = $options['initialDocument'];
        }
        if (isset($options['initialVariables'])) {
            $initialState['variables'] = $options['initialVariables'];
        }
        if (isset($options['initialHeaders'])) {
            $initialState['headers'] = $options['initialHeaders'];
        }

        $initialStateJson = !empty($initialState)
            ? json_encode($initialState, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : 'null';

        $subEndpoint = isset($options['subscriptionEndpoint'])
            ? json_encode((string) $options['subscriptionEndpoint'], JSON_UNESCAPED_SLASHES)
            : (isset($options['initialSubscriptionEndpoint'])
                ? json_encode((string) $options['initialSubscriptionEndpoint'], JSON_UNESCAPED_SLASHES)
                : 'null');

        $theme = isset($options['theme']) && $options['theme'] === 'light' ? "'light'" : "'dark'";

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{$title}</title>
    <style>
        html, body {
            margin: 0;
            padding: 0;
            width: 100vw;
            height: 100vh;
            overflow: hidden;
            background-color: #12151a;
        }
        #sandbox {
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }
    </style>
</head>
<body>
    <div id="sandbox"></div>
    <script src="{$cdnUrl}"></script>
    <script>
        (function() {
            var rawEndpoint = {$endpointUrl};
            var endpoint = rawEndpoint;
            if (rawEndpoint.startsWith('/')) {
                endpoint = window.location.origin + rawEndpoint;
            } else if (!rawEndpoint.startsWith('http://') && !rawEndpoint.startsWith('https://')) {
                endpoint = window.location.origin + '/' + rawEndpoint;
            }

            var config = {
                target: '#sandbox',
                initialEndpoint: endpoint,
                includeCookies: {$includeCookies},
                theme: {$theme}
            };

            var initialState = {$initialStateJson};
            if (initialState) {
                config.initialState = initialState;
            }

            var subEndpoint = {$subEndpoint};
            if (subEndpoint) {
                if (subEndpoint.startsWith('/')) {
                    var wsProto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
                    subEndpoint = wsProto + '//' + window.location.host + subEndpoint;
                }
                config.initialSubscriptionEndpoint = subEndpoint;
            }

            new window.EmbeddedSandbox(config);
        })();
    </script>
</body>
</html>
HTML;
    }
}
