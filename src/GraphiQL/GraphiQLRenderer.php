<?php

declare(strict_types=1);

namespace Typic\GraphiQL;

class GraphiQLRenderer
{
    /**
     * @param array<string, mixed> $options
     */
    public function render(string $endpoint = '/graphql', array $options = []): string
    {
        $title = htmlspecialchars((string) ($options['title'] ?? 'Typic GraphiQL Explorer'), ENT_QUOTES, 'UTF-8');
        $endpointUrl = htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{$title}</title>
    <link rel="stylesheet" href="https://unpkg.com/graphiql/graphiql.min.css" />
    <style>
        body {
            margin: 0;
            width: 100vw;
            height: 100vh;
            overflow: hidden;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }
        #graphiql {
            height: 100vh;
        }
    </style>
</head>
<body>
    <div id="graphiql">Loading Typic Explorer...</div>
    <script src="https://unpkg.com/react/umd/react.production.min.js"></script>
    <script src="https://unpkg.com/react-dom/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/graphiql/graphiql.min.js"></script>
    <script>
        const fetcher = GraphiQL.createFetcher({
            url: '{$endpointUrl}',
        });
        ReactDOM.render(
            React.createElement(GraphiQL, {
                fetcher: fetcher,
                defaultEditorTab: 'query',
            }),
            document.getElementById('graphiql')
        );
    </script>
</body>
</html>
HTML;
    }
}
