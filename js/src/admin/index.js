import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';

/*
 * Settings and permissions in Flarum's own pages. Each is a callback: the
 * translator is not ready when this module is evaluated.
 */
const tr = (key) => app.translator.trans(`ernestdefoe-earshot.admin.${key}`);

const perm = (permission, icon, section) => [
  () => ({ icon, permission, label: tr(`permissions.${permission.split('.')[1]}`), allowGuest: permission === 'earshot.view' }),
  section,
];

export const extend = [
  new Extend.Admin()
    .setting(() => ({ setting: 'ernestdefoe-earshot.nav', type: 'boolean', label: tr('settings.nav') }))
    .setting(() => ({
      setting: 'ernestdefoe-earshot.fetch_minutes',
      type: 'number',
      min: 15,
      label: tr('settings.fetch_minutes'),
      help: tr('settings.fetch_minutes_help'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-earshot.backlog',
      type: 'number',
      min: 1,
      max: 25,
      label: tr('settings.backlog'),
      help: tr('settings.backlog_help'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-earshot.max_audio_mb',
      type: 'number',
      min: 1,
      label: tr('settings.max_audio_mb'),
      help: tr('settings.max_audio_mb_help'),
    }))
    .permission(...perm('earshot.view', 'fas fa-podcast', 'view'))
    .permission(...perm('earshot.follow', 'fas fa-bell', 'reply'))
    .permission(...perm('earshot.manage', 'fas fa-microphone', 'moderate')),
];

app.initializers.add('ernestdefoe-earshot', () => {});
