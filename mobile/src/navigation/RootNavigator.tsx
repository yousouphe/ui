// Role-based navigation shell. The server-verified role on the authenticated user selects the
// sender or rider tab tree; logged-out users get the auth stack. Admin/ops stays on web.
import React from 'react';
import { NavigationContainer, DefaultTheme } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { useTranslation } from 'react-i18next';
import { useAuth } from '@/auth/AuthContext';
import { LoginScreen } from '@/screens/LoginScreen';
import {
  NotificationsScreen,
  ProfileScreen,
  RegisterScreen,
  RiderHomeScreen,
  RiderWalletScreen,
  SenderHomeScreen,
  SenderOrdersScreen,
} from '@/screens';
import { CreateBookingScreen, PayScreen, RateScreen, RidersScreen, TrackScreen } from '@/screens/sender/flow';
import { colors } from '@/theme/theme';

const Stack = createNativeStackNavigator();
const Tab = createBottomTabNavigator();

const navTheme = {
  ...DefaultTheme,
  colors: { ...DefaultTheme.colors, background: colors.bg, primary: colors.primary, card: colors.surface, text: colors.text, border: colors.border },
};

function AuthStack() {
  return (
    <Stack.Navigator screenOptions={{ headerShown: false }}>
      <Stack.Screen name="Login" component={LoginScreen} />
      <Stack.Screen name="Register" component={RegisterScreen} options={{ headerShown: true, title: '' }} />
    </Stack.Navigator>
  );
}

function SenderTabs() {
  const { t } = useTranslation();
  return (
    <Tab.Navigator screenOptions={{ tabBarActiveTintColor: colors.primary, tabBarInactiveTintColor: colors.textSoft, headerShown: false }}>
      <Tab.Screen name="SenderHome" component={SenderHomeScreen} options={{ title: t('nav.home') }} />
      <Tab.Screen name="SenderOrders" component={SenderOrdersScreen} options={{ title: t('nav.orders') }} />
      <Tab.Screen name="SenderAlerts" component={NotificationsScreen} options={{ title: t('nav.notifications') }} />
      <Tab.Screen name="SenderProfile" component={ProfileScreen} options={{ title: t('nav.profile') }} />
    </Tab.Navigator>
  );
}

function RiderTabs() {
  const { t } = useTranslation();
  return (
    <Tab.Navigator screenOptions={{ tabBarActiveTintColor: colors.primary, tabBarInactiveTintColor: colors.textSoft, headerShown: false }}>
      <Tab.Screen name="RiderHome" component={RiderHomeScreen} options={{ title: t('nav.home') }} />
      <Tab.Screen name="RiderWallet" component={RiderWalletScreen} options={{ title: t('nav.wallet') }} />
      <Tab.Screen name="RiderAlerts" component={NotificationsScreen} options={{ title: t('nav.notifications') }} />
      <Tab.Screen name="RiderProfile" component={ProfileScreen} options={{ title: t('nav.profile') }} />
    </Tab.Navigator>
  );
}

// The sender side wraps the tabs in a stack so the booking flow (create → riders → track → pay
// → rate) can be pushed over them.
function SenderStack() {
  return (
    <Stack.Navigator screenOptions={{ headerBackTitleVisible: false }}>
      <Stack.Screen name="SenderTabs" component={SenderTabs} options={{ headerShown: false }} />
      <Stack.Screen name="CreateBooking" component={CreateBookingScreen} options={{ title: 'New delivery' }} />
      <Stack.Screen name="Riders" component={RidersScreen} options={{ title: 'Choose a rider' }} />
      <Stack.Screen name="Track" component={TrackScreen} options={{ title: 'Track' }} />
      <Stack.Screen name="Pay" component={PayScreen} options={{ title: 'Payment' }} />
      <Stack.Screen name="Rate" component={RateScreen} options={{ title: 'Rate' }} />
    </Stack.Navigator>
  );
}

export function RootNavigator() {
  const { user } = useAuth();
  return (
    <NavigationContainer theme={navTheme}>
      {!user ? <AuthStack /> : user.role === 'rider' ? <RiderTabs /> : <SenderStack />}
    </NavigationContainer>
  );
}
