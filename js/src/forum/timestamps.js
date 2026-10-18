import { player } from './player';
import { parseClock, ts } from './util';

const PATTERN = /(^|[^\d:])((?:\d{1,2}:)?\d{1,3}:[0-5]\d)(?![\d:])/g;
const SKIP = 'a, code, pre, button, .EarshotStamp';

/**
 * "18:42" in a reply to an episode becomes a play-from-here chip. Text nodes
 * only, never inside links, code or a chip already made, and only times that
 * fall inside the episode, so a score of "3:1" or a time of day stays text.
 */
export function linkTimestamps(body, data) {
  if (!body) return;
  const { episode, show } = data;
  const limit = episode.duration || 6 * 3600;
  const walker = document.createTreeWalker(body, NodeFilter.SHOW_TEXT, {
    acceptNode: (node) => (node.parentElement.closest(SKIP) || !/\d:\d\d/.test(node.nodeValue) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT),
  });
  const nodes = [];
  while (walker.nextNode()) nodes.push(walker.currentNode);

  nodes.forEach((node) => {
    const text = node.nodeValue;
    const frag = document.createDocumentFragment();
    let last = 0;
    let found = false;
    text.replace(PATTERN, (match, before, stamp, offset) => {
      const seconds = parseClock(stamp);
      if (seconds === null || seconds > limit) return match;
      const start = offset + before.length;
      frag.appendChild(document.createTextNode(text.slice(last, start)));
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'EarshotStamp';
      chip.textContent = stamp;
      chip.title = ts('player.play_from', { time: stamp });
      chip.addEventListener('click', () => player.play(episode, show, seconds));
      frag.appendChild(chip);
      last = start + stamp.length;
      found = true;
      return match;
    });
    if (!found) return;
    frag.appendChild(document.createTextNode(text.slice(last)));
    node.parentNode.replaceChild(frag, node);
  });
}
