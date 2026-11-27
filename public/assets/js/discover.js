// LightGroup · 发现：搜索同群的人，或按资料相似度推荐
import { S, h, icon, btn, api, go, pageHead, toast } from './core.js';
import { avatar } from './avatar.js';

function genderText(g) {
  return g === 'male' ? '男' : (g === 'female' ? '女' : (g === 'other' ? '其它' : ''));
}

function dcCard(it, showScore) {
  const card = document.createElement('button');
  card.type = 'button';
  card.className = 'dc-card';
  card.appendChild(avatar(it, 46));

  const main = h('div', 'dc-main');
  const top = h('div', 'dc-top');
  top.appendChild(h('b', '', it.name || '匿名'));
  if (it.username && it.username !== it.name) top.appendChild(h('span', 'dc-meta', '@' + it.username));
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
  if (showScore && it.score) card.appendChild(h('span', 'dc-score', String(it.score)));
  card.addEventListener('click', function () { go('#/u/' + it.uid); });
  return card;
}

export function renderDiscover(host) {
  host.textContent = '';
  const page = h('div', 'chat-page');
  page.appendChild(pageHead('发现', '按 ID、邮箱、用户名找人，或者看看聊得来的人'));
  const body = h('div', 'page-body');
  page.appendChild(body);
  host.appendChild(page);

  let q = '';
  let reqId = 0;

  const search = h('div', 'dc-search');
  search.appendChild(icon('fa-magnifying-glass'));
  const inp = document.createElement('input');
  inp.className = 'tb dc-find';
  inp.type = 'search';
  inp.placeholder = 'ID、邮箱、用户名';
  search.appendChild(inp);
  const goBtn = btn('搜索', 'btn btn-primary btn-sm', function () {
    q = inp.value.trim();
    load();
  });
  search.appendChild(goBtn);
  body.appendChild(search);

  const box = h('div', 'dc-box');
  body.appendChild(box);

  inp.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { q = inp.value.trim(); load(); }
  });

  function load() {
    const mine = ++reqId;
    box.textContent = '';
    box.appendChild(h('div', 'pf-empty', '正在找…'));
    const url = q === '' ? 'api/discover' : 'api/discover?q=' + encodeURIComponent(q);
    api(url).then(function (j) {
      if (mine !== reqId) return;
      box.textContent = '';
      if (!j || !j.ok) {
        box.appendChild(h('div', 'pf-empty', (j && j.err) || '加载失败'));
        return;
      }
      if (j.mode === 'search') { paintSearch(j); return; }
      paintMatch(j);
    }).catch(function (e) {
      if (mine !== reqId) return;
      box.textContent = '';
      box.appendChild(h('div', 'pf-empty', String((e && e.message) || '加载失败')));
    });
  }

  function paintSearch(j) {
    const items = j.items || [];
    if (!items.length) {
      box.appendChild(h('div', 'pf-empty', '没有找到「' + q + '」'));
      return;
    }
    box.appendChild(h('div', 'dc-head', '搜索结果 · ' + items.length));
    const list = h('div', 'dc-list');
    items.forEach(function (it) { list.appendChild(dcCard(it, false)); });
    box.appendChild(list);
  }

  function paintMatch(j) {
    const mine = j.mine || {};
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
      box.appendChild(tip);
    }

    const items = j.items || [];
    if (items.length) {
      box.appendChild(h('div', 'dc-head', '聊得来的人'));
      const list = h('div', 'dc-list');
      items.forEach(function (it) { list.appendChild(dcCard(it, true)); });
      box.appendChild(list);
    } else {
      box.appendChild(h('div', 'pf-empty', '还没有可推荐的人，等更多成员填好资料再来。'));
    }

    const sg = j.suggest || [];
    if (sg.length) {
      box.appendChild(h('div', 'dc-head', '猜你想了解'));
      const list2 = h('div', 'dc-list');
      sg.forEach(function (it) { list2.appendChild(dcCard(it, false)); });
      box.appendChild(list2);
    }
  }

  load();
}
