/**
 * Notification store - wrapper for @kyvg/vue3-notification
 */
import { defineStore } from 'pinia'
import { useNotification } from '@kyvg/vue3-notification'

export const useNotifyStore = defineStore('notify', () => {
  const notification = useNotification()

  return {
    success(message) {
      notification.notify({
        title: 'Success',
        text: message,
        type: 'success',
      })
    },

    error(message) {
      notification.notify({
        title: 'Error',
        text: message,
        type: 'error',
      })
    },

    warning(message) {
      notification.notify({
        title: 'Warning',
        text: message,
        type: 'warn',
      })
    },

    info(message) {
      notification.notify({
        title: 'Info',
        text: message,
        type: 'info',
      })
    },
  }
})
