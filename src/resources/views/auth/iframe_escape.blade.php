<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <base target="_top">

        <title>Redirecting...</title>

        <meta name="shopify-api-key" content="{{ $apiKey }}" />
        <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function () {
                var url = @json($escapeUrl);
                var link = document.createElement('a');
                link.href = url;
                open(link.href, '_top');
            });
        </script>
    </head>
    <body>
    </body>
</html>
