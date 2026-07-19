// i18n bootstrap (English + Hausa), matching the web app's two locales. Keys live here in the
// scaffold; Phase 5/6 expand them (and can source from the shared translation keys). The device
// language is detected, falling back to English.
import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

export const resources = {
  en: {
    translation: {
      'common.brand': 'Aike',
      'common.retry': 'Try again',
      'common.loading': 'Loading…',
      'auth.email': 'Email',
      'auth.password': 'Password',
      'auth.signIn': 'Sign in',
      'auth.signUp': 'Create account',
      'auth.forgot': 'Forgot password?',
      'auth.noAccount': "Don't have an account?",
      'nav.home': 'Home',
      'nav.orders': 'Orders',
      'nav.jobs': 'Jobs',
      'nav.wallet': 'Wallet',
      'nav.notifications': 'Alerts',
      'nav.profile': 'Profile',
      'sender.newDelivery': 'Request a delivery',
      'rider.goOnline': 'Go online',
      'rider.goOffline': 'Go offline',
      'empty.noOrders': 'No orders yet',
      'error.generic': 'Something went wrong.',
      'error.offline': "You're offline — some features may pause until you reconnect.",
    },
  },
  ha: {
    translation: {
      'common.brand': 'Aike',
      'common.retry': 'Sake gwadawa',
      'common.loading': 'Ana loda…',
      'auth.email': 'Imel',
      'auth.password': 'Kalmar sirri',
      'auth.signIn': 'Shiga',
      'auth.signUp': 'Buɗe asusu',
      'auth.forgot': 'Ka manta kalmar sirri?',
      'auth.noAccount': 'Ba ka da asusu?',
      'nav.home': 'Gida',
      'nav.orders': 'Odoji',
      'nav.jobs': 'Ayyuka',
      'nav.wallet': 'Walat',
      'nav.notifications': 'Sanarwa',
      'nav.profile': 'Bayani',
      'sender.newDelivery': 'Nemi jigilar kaya',
      'rider.goOnline': 'Kunna kai tsaye',
      'rider.goOffline': 'Kashe kai tsaye',
      'empty.noOrders': 'Babu odoji tukuna',
      'error.generic': 'Wani abu ya gaza.',
      'error.offline': 'Ba ka da yanar gizo — wasu abubuwa na iya tsayawa har sai an dawo.',
    },
  },
};

if (!i18n.isInitialized) {
  i18n.use(initReactI18next).init({
    resources,
    lng: 'en',
    fallbackLng: 'en',
    interpolation: { escapeValue: false },
  });
}

export default i18n;
