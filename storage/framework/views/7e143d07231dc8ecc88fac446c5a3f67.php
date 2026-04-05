<!DOCTYPE html>
<html data-theme="<?php echo e($defaultPreferences['theme']); ?>" lang="<?php echo e($lang); ?>">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="<?php echo e(__('message.2fauth_description')); ?>" lang="<?php echo e($lang); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0, shrink-to-fit=no, viewport-fit=cover">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- PWA Meta Tags -->
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="2FA-Vault">
    <meta name="theme-color" content="#4f46e5">
    <meta name="msapplication-TileColor" content="#4f46e5">
    
    <title><?php echo e(config('app.name')); ?></title>

    <!-- Favicons -->
    <link rel="shortcut icon" href="<?php echo e(asset('favicon.ico')); ?>" />
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo e(asset('icons/favicon-32x32.png')); ?>" />
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo e(asset('icons/favicon-16x16.png')); ?>" />
    
    <!-- Apple Touch Icons -->
    <link rel="apple-touch-icon" href="<?php echo e(asset('icons/apple-touch-icon.png')); ?>" />
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo e(asset('icons/apple-touch-icon.png')); ?>" />
    
    <!-- PWA Manifest -->
    <link rel="manifest" href="<?php echo e(asset('manifest.json')); ?>">

</head>
<body>
    <div id="app">
        <app></app>
    </div>
    <script type="text/javascript"
        <?php echo isset($cspNonce) ? "nonce='" . $cspNonce . "'" : ""; ?> >
        var appSettings = <?php echo $appSettings; ?>;
        var appConfig = <?php echo $appConfig; ?>;
        var urls = <?php echo $urls; ?>;
        var defaultPreferences = <?php echo $defaultPreferences->toJson(); ?>;
        var lockedPreferences = <?php echo $lockedPreferences->toJson(); ?>;
        var appVersion = '<?php echo e(config("2fauth.version")); ?>';
        var isDemoApp = <?php echo $isDemoApp; ?>;
        var isTestingApp = <?php echo $isTestingApp; ?>;
        var appLocales = <?php echo $locales; ?>;
    </script>
    <?php echo app('Illuminate\Foundation\Vite')('resources/js/app.js'); ?>
</body>
</html><?php /**PATH /srv/resources/views/landing.blade.php ENDPATH**/ ?>