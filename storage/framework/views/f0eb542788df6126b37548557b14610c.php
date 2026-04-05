<?php $__env->startComponent('mail::message'); ?>
<?php echo app('translator')->get('message.notifications.hello_user', ['username' => $account->name]); ?>
<br/><br/>
**<?php echo app('translator')->get('message.notifications.failed_login.resume'); ?>**<br/>
<?php echo app('translator')->get('message.notifications.failed_login.connection_details'); ?>:

<?php if (isset($component)) { $__componentOriginal91214b38020aa1d764d4a21e693f703c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal91214b38020aa1d764d4a21e693f703c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => $__env->getContainer()->make(Illuminate\View\Factory::class)->make('mail::panel'),'data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('mail::panel'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo app('translator')->get('label.time'); ?>: **<?php echo e($time->toCookieString()); ?>**<br/>
<?php echo app('translator')->get('label.ip_address'); ?>: **<?php echo e($ipAddress); ?>**<br/>
<?php echo app('translator')->get('label.device'); ?>: **<?php echo app('translator')->get('message.browser_on_platform', ['browser' => $browser, 'platform' => $platform]); ?>**<br/>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal91214b38020aa1d764d4a21e693f703c)): ?>
<?php $attributes = $__attributesOriginal91214b38020aa1d764d4a21e693f703c; ?>
<?php unset($__attributesOriginal91214b38020aa1d764d4a21e693f703c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal91214b38020aa1d764d4a21e693f703c)): ?>
<?php $component = $__componentOriginal91214b38020aa1d764d4a21e693f703c; ?>
<?php unset($__componentOriginal91214b38020aa1d764d4a21e693f703c); ?>
<?php endif; ?>

<?php echo app('translator')->get('message.notifications.failed_login.recommandations'); ?><br/>

<?php echo app('translator')->get('message.notifications.regards'); ?>,<br/>
<?php echo e(config('app.name')); ?>

<?php echo $__env->renderComponent(); ?>
<?php /**PATH /srv/resources/views/emails/failedLogin.blade.php ENDPATH**/ ?>