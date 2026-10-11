<?php

declare(strict_types=1);

namespace Typic\Explorer;

class GraphiQLRenderer implements ExplorerInterface
{
    public const GRAPHIQL_CSS = 'https://cdn.jsdelivr.net/npm/graphiql@3/graphiql.min.css';
    public const REACT_JS = 'https://cdn.jsdelivr.net/npm/react@18/umd/react.production.min.js';
    public const REACT_DOM_JS = 'https://cdn.jsdelivr.net/npm/react-dom@18/umd/react-dom.production.min.js';
    public const GRAPHIQL_JS = 'https://cdn.jsdelivr.net/npm/graphiql@3/graphiql.min.js';
    public const GRAPHQL_WS_JS = 'https://cdn.jsdelivr.net/npm/graphql-ws@5/umd/graphql-ws.min.js';

    /**
     * @param array{
     *     title?: string,
     *     endpoint?: string,
     *     subscriptionEndpoint?: string,
     *     initialSubscriptionEndpoint?: string,
     *     initialDocument?: string,
     *     initialVariables?: array<string, mixed>|string,
     *     initialHeaders?: array<string, string>,
     *     theme?: 'dark'|'light',
     * } $options
     */
    public function render(string $endpoint = '/graphql', array $options = []): string
    {
        $title = htmlspecialchars((string) ($options['title'] ?? 'Typic GraphiQL'), ENT_QUOTES, 'UTF-8');
        $endpointUrl = json_encode($endpoint, JSON_UNESCAPED_SLASHES);

        $subEndpoint = $options['subscriptionEndpoint']
            ?? ($options['initialSubscriptionEndpoint'] ?? null);
        $subEndpointJson = $subEndpoint !== null
            ? json_encode((string) $subEndpoint, JSON_UNESCAPED_SLASHES)
            : 'null';

        $defaultQuery = $options['initialDocument'] ?? null;
        $defaultQueryJson = $defaultQuery !== null
            ? json_encode((string) $defaultQuery, JSON_UNESCAPED_SLASHES)
            : 'null';

        $initialVariables = $options['initialVariables'] ?? null;
        $initialVariablesJson = $initialVariables !== null
            ? (is_string($initialVariables) ? json_encode($initialVariables) : json_encode(json_encode($initialVariables, JSON_PRETTY_PRINT)))
            : 'null';

        $initialHeaders = $options['initialHeaders'] ?? null;
        $initialHeadersJson = $initialHeaders !== null
            ? json_encode(json_encode($initialHeaders, JSON_PRETTY_PRINT))
            : 'null';

        $theme = ($options['theme'] ?? 'dark') === 'light' ? 'graphiql-light' : 'graphiql-dark';

        $graphiqlCss = self::GRAPHIQL_CSS;
        $reactJs = self::REACT_JS;
        $reactDomJs = self::REACT_DOM_JS;
        $graphiqlJs = self::GRAPHIQL_JS;
        $graphqlWsJs = self::GRAPHQL_WS_JS;

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{$title}</title>
    <link rel="stylesheet" href="{$graphiqlCss}" />
    <style>
        html, body {
            height: 100%;
            margin: 0;
            width: 100%;
            overflow: hidden;
            background-color: #0b0d11;
        }
        #graphiql {
            height: 100vh;
        }
    </style>
</head>
<body class="{$theme}">
    <div id="graphiql">Loading GraphiQL...</div>
    <script src="{$reactJs}"></script>
    <script src="{$reactDomJs}"></script>
    <script src="{$graphqlWsJs}"></script>
    <script src="{$graphiqlJs}"></script>
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
            var wsClient = null;

            if (subEndpoint && window.graphqlWs) {
                var wsUrl = subEndpoint;
                if (wsUrl.startsWith('/')) {
                    var wsProto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
                    wsUrl = wsProto + '//' + window.location.host + wsUrl;
                }
                wsClient = window.graphqlWs.createClient({
                    url: wsUrl,
                });
            }

            function fetcher(graphQLParams, opts) {
                var queryStr = (graphQLParams.query || '').trim();
                var isSubscription = queryStr.startsWith('subscription');

                if (isSubscription && wsClient) {
                    return {
                        subscribe: function(observer) {
                            return wsClient.subscribe(graphQLParams, observer);
                        }
                    };
                }

                var headers = Object.assign({
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                }, (opts && opts.headers) || {});

                return fetch(endpoint, {
                    method: 'POST',
                    headers: headers,
                    body: JSON.stringify(graphQLParams),
                }).then(function(response) {
                    return response.json();
                });
            }

            var defaultQuery = {$defaultQueryJson};
            var defaultVariables = {$initialVariablesJson};
            var defaultHeaders = {$initialHeadersJson};

            var props = {
                fetcher: fetcher,
                headerEditorEnabled: true,
                shouldPersistHeaders: true,
                inputValueDeprecation: true
            };

            if (defaultQuery) {
                props.defaultQuery = defaultQuery;
            }
            if (defaultVariables) {
                props.variables = defaultVariables;
            }
            if (defaultHeaders) {
                props.headers = defaultHeaders;
            }

            var root = ReactDOM.createRoot ? ReactDOM.createRoot(document.getElementById('graphiql')) : null;
            if (root) {
                root.render(React.createElement(GraphiQL, props));
            } else {
                ReactDOM.render(React.createElement(GraphiQL, props), document.getElementById('graphiql'));
            }
        })();
    </script>
</body>
</html>
HTML;
    }
}
