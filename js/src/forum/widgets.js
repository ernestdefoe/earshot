/*
 * The Latest episodes widget, offered to Bespoke and to fof/forum-widgets-core.
 *
 * Both hosts are resolved at runtime, never imported, so the bundle is the same
 * whichever (or neither) is installed. Registering only offers the widget: its
 * code is in the lazy chunk and loads when a host actually places it.
 */
import { fof, hasFofWidgets, placed } from '../common/fofWidgets';

const flarumGlobal = () => (typeof globalThis !== 'undefined' ? globalThis.flarum : undefined);

export default function registerWidgets(app, load) {
  const loaded = { mod: null };
  const content = () => {
    if (!app.forum.attribute('canViewEarshot')) return null;
    if (loaded.mod) return loaded.mod.LatestEpisodes.component();
    load().then((mod) => {
      loaded.mod = mod;
      m.redraw();
    });
    return null;
  };

  const flarum = flarumGlobal();
  if (!flarum || !flarum.extensions) return;

  if (hasFofWidgets() && app.widgets) {
    const Widgets = fof('common/extend/Widgets');
    const WidgetBase = fof('common/components/Widget');
    if (Widgets && WidgetBase) {
      class EarshotLatestWidget extends WidgetBase {
        className() {
          return 'EarshotFofWidget';
        }
        icon() {
          return 'fas fa-podcast';
        }
        title() {
          return app.translator.trans('ernestdefoe-earshot.forum.widget.title');
        }
        content() {
          return content();
        }
      }
      new Widgets()
        .add({
          key: 'earshotLatest',
          component: EarshotLatestWidget,
          // 🚨 Opt-in: only where the admin placed it in FoF's layout editor,
          // and only for a viewer allowed to see podcasts (see common/fofWidgets.js).
          isDisabled: () => !placed(app, 'ernestdefoe-earshot', 'earshotLatest') || !app.forum.attribute('canViewEarshot'),
          isUnique: true,
          placement: 'end',
          position: 2,
        })
        .extend(app, 'ernestdefoe-earshot');
    }
  }

  if ('ernestdefoe-bespoke' in flarum.extensions) {
    globalThis.BespokeWidgetQueue = globalThis.BespokeWidgetQueue || [];
    globalThis.BespokeWidgetQueue.push({
      type: 'earshot-latest',
      // Translation keys, not translated text: Bespoke resolves them itself.
      label: 'ernestdefoe-earshot.forum.widget.title',
      icon: 'fas fa-podcast',
      zones: ['sidebar', 'above-list', 'below-list', 'footer'],
      schema: [{ key: 'heading', type: 'text', label: 'ernestdefoe-earshot.forum.widget.heading', default: '' }],
      component: {
        view(vnode) {
          const heading = vnode.attrs?.settings?.heading;
          return (
            <div className="EarshotBespokeWidget">
              {heading ? <h4 className="EarshotBespokeWidget-title">{heading}</h4> : null}
              {content()}
            </div>
          );
        },
      },
    });
  }
}
