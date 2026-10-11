/**
 * Рамка панели чата (/chat/panel) — только то, что нужно своему в ней.
 *
 * Панель — компонент пакета siteko/chatbot, её скрипты (`chatbot::widget-scripts`)
 * подключает сама страница. Здесь — форма заявки: маска телефона, как на
 * витрине, и тихое продление сессии вместо «This page has expired»: рамку,
 * как и страницу, держат открытой часами.
 */
import { initRuPhoneMask } from './modules/phone-mask-ru';
import './modules/livewire-session-guard';

initRuPhoneMask();
