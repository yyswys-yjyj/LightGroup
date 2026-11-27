// LightGroup · 发现：按生日算出的年龄、标签、性别、签名做匹配
import { S, h, icon, btn, api, go, pageHead, toast } from './core.js';
import { avatar } from './avatar.js';

function genderText(g) {
  return g === 'male' ? '男' : (g === 'female' ? '女' : (g === 'other' ? '其它' : ''));
}

export function renderDiscover(host) {
  host.textContent = '';
  const page = h('div', 'chat-page');
  page.appendChild(pageHead('发现', '按资料找到聊得来的人'));
  const body = h('div', 'page-body');
  page.appendChild(body);
  host.appendChild(page);
  body.appendChild(h('div', 'pf-empty', '正在找…'));

  api('api/discover').then(function (j) {
    body.textContent = '';
    if (!j || !j.ok) {
      body.appendChild(h('div', 'pf-empty', (j && j.err) || '加载失败'));
      return;
    }
    const mine = j.mine || {};
    if (j.items && j.items.length) {
      body.appendChild(h('div', 'page-tip', '点进去可以加好友。'));
    }

    if (!mine.age || !(mine.tags && mine.tags.length)) {
      const tip = h('div', 'dc-tip');
      const txt = h('div', 'dc-tip-txt');
      txt.appendChild(h('b', '', '资料再全一点，匹配会更准'));
      txt.appendChild(h('span', '', [
        mine.age ? '' : '补上生日能算年龄',
        (mine.tags && mine.tags.length) ? '' : '加几个标签',
        mine.signature ? '' : '写一句签名'
      ].filter(Boolean).join('、') || '去资料页看看'));
      tip.appendChild(txt);
      tip.appendChild(btn('去完善', 'btn btn-sm', function () { go('#/me'); }));
      body.appendChild(tip);
    }

    const list = h('div', 'dc-list');
    (j.items || []).forEach(function (it) {
      const card = document.createElement('button');
      card.type = 'button';
      card.className = 'dc-card';
      card.appendChild(avatar(it, 46));

      const main = h('div', 'dc-main');
      const top = h('div', 'dc-top');
      top.appendChild(h('b', '', it.name || '匿名'));
      if (it.age) top.appendChild(h('span', 'dc-meta', it.age + ' 岁'));
      if (it.gender) top.appendChild(h('span', 'dc-meta', genderText(it.gender)));
      main.appendChild(top);
      if (it.signature) main.appendChild(h('div', 'dc-sign', it.signature));
      if (Array.isArray(it.tags) && it.tags.length) {
        const tl = h('div', 'pf-tags pf-tags-sm');
        it.tags.slice(0, 5).forEach(function (t) { tl.appendChild(h('span', 'pf-tag', '#' + t)); });
        main.appendChild(tl);
      }
      if (Array.isArray(it.why) && it.why.length) {
        main.appendChild(h('div', 'dc-why', it.why.join(' · ')));
      }
      card.appendChild(main);
      card.appendChild(h('span', 'dc-score', String(it.score)));
      card.addEventListener('click', function () { go('#/u/' + it.uid); });
      list.appendChild(card);
    });
    if (!(j.items || []).length) {
      body.appendChild(h('div', 'pf-empty', '还没有可推荐的人，等更多成员填好资料再来。'));
    }
    body.appendChild(list);
  }).catch(function (e) {
    body.textContent = '';
    body.appendChild(h('div', 'pf-empty', String((e && e.message) || '加载失败')));
  });
}
