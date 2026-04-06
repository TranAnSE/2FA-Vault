/**
 * Push Notifications Service
 * Handles Web Push API integration for notifications
 */

import { httpClientFactory } from '@/services/httpClientFactory'

const apiClient = httpClientFactory('api')

class PushNotificationsService {
  constructor() {
    this.swRegistration = null;
    this.subscription = null;
    this.publicKey = null;
  }

  /**
   * Initialize push notifications
   * @param {ServiceWorkerRegistration} swRegistration
   */
  async init(swRegistration) {
    if (!('PushManager' in window)) {
      console.warn('[Push] Push notifications not supported');
      return false;
    }

    this.swRegistration = swRegistration;

    await this.getPublicKey();

    this.subscription = await this.swRegistration.pushManager.getSubscription();

    return true;
  }

  /**
   * Get VAPID public key from server
   */
  async getPublicKey() {
    const response = await apiClient.get('/push/public-key')
    this.publicKey = response.data.publicKey
  }

  /**
   * Request notification permission
   */
  async requestPermission() {
    if (!('Notification' in window)) {
      throw new Error('Notifications not supported');
    }

    const permission = await Notification.requestPermission();
    return permission === 'granted';
  }

  /**
   * Subscribe to push notifications
   */
  async subscribe() {
    if (!this.swRegistration) {
      throw new Error('Service Worker not registered');
    }

    if (!this.publicKey) {
      await this.getPublicKey();
    }

    const permitted = await this.requestPermission();
    if (!permitted) {
      throw new Error('Notification permission denied');
    }

    this.subscription = await this.swRegistration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: this.urlBase64ToUint8Array(this.publicKey)
    });

    await this.sendSubscriptionToServer(this.subscription);

    return this.subscription;
  }

  /**
   * Unsubscribe from push notifications
   */
  async unsubscribe() {
    if (!this.subscription) {
      return;
    }

    await this.subscription.unsubscribe();
    await this.removeSubscriptionFromServer(this.subscription);
    this.subscription = null;
  }

  /**
   * Send subscription to server
   */
  async sendSubscriptionToServer(subscription) {
    await apiClient.post('/push/subscribe', {
      endpoint: subscription.endpoint,
      keys: {
        p256dh: this.arrayBufferToBase64(subscription.getKey('p256dh')),
        auth: this.arrayBufferToBase64(subscription.getKey('auth'))
      }
    })
  }

  /**
   * Remove subscription from server
   */
  async removeSubscriptionFromServer(subscription) {
    await apiClient.delete('/push/unsubscribe', {
      data: { endpoint: subscription.endpoint }
    })
  }

  /**
   * Send test notification
   */
  async sendTestNotification() {
    await apiClient.post('/push/test')
  }

  /**
   * Check if user is subscribed
   */
  isSubscribed() {
    return this.subscription !== null;
  }

  /**
   * Get subscription status
   */
  getStatus() {
    return {
      supported: 'PushManager' in window,
      permission: typeof Notification !== 'undefined' ? Notification.permission : 'default',
      subscribed: this.isSubscribed(),
      subscription: this.subscription
    };
  }

  /**
   * Convert URL-safe base64 to Uint8Array
   */
  urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding)
      .replace(/\-/g, '+')
      .replace(/_/g, '/');

    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i);
    }

    return outputArray;
  }

  /**
   * Convert ArrayBuffer to base64
   */
  arrayBufferToBase64(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.byteLength; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return window.btoa(binary);
  }
}

export default new PushNotificationsService();
