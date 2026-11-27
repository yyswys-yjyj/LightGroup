// LightGroup · 住址选择器：省 → 市 → 区/县，乡镇街道手填
// 数据在 /assets/data/regions.json（省市区三级，约 30KB），只在打开选择器时加载
import { h, btn, openSheet, closeSheet, toast } from './core.js';

let CACHE = null;
let LOADING = null;

function loadRegions() {
  if (CACHE) return Promise.resolve(CACHE);
  if (LOADING) return LOADING;
  LOADING = fetch('/assets/data/regions.json', { credentials: 'same-origin' })
    .then(function (r) {
      if (!r.ok) throw new Error('地区数据加载失败');
      return r.json();
    })
    .then(function (j) {
      CACHE = j;
      LOADING = null;
      return j;
    })
    .catch(function (e) {
      LOADING = null;
      throw e;
    });
  return LOADING;
}

/** 把「省 + 市 + 区 + 街道」拼成一行；直辖市的「市辖区」不重复写 */
export function joinAddress(prov, city, dist, street) {
  const parts = [];
  if (prov) parts.push(prov);
  if (city && city !== '市辖区' && city !== prov) parts.push(city);
  if (dist && dist !== '市辖区') parts.push(dist);
  const s = String(street || '').trim();
  if (s) parts.push(s);
  return parts.join('');
}

function makeSelect(cls) {
  const sel = document.createElement('select');
  sel.className = 'tb rg-sel' + (cls ? ' ' + cls : '');
  return sel;
}

function fillSelect(sel, items, placeholder) {
  sel.textContent = '';
  const o0 = document.createElement('option');
  o0.value = '';
  o0.textContent = placeholder;
  sel.appendChild(o0);
  items.forEach(function (it, i) {
    const o = document.createElement('option');
    o.value = String(i);
    o.textContent = it;
    sel.appendChild(o);
  });
}

/** 从现有住址文本里猜省市区的下标，猜不到就是 -1 */
function guess(list, text) {
  const g = { p: -1, c: -1, d: -1 };
  if (!text) return g;
  for (let i = 0; i < list.length; i++) {
    if (text.indexOf(list[i].n) >= 0) { g.p = i; break; }
  }
  if (g.p < 0) return g;
  const cities = list[g.p].c || [];
  for (let i = 0; i < cities.length; i++) {
    const nm = cities[i].n;
    if (nm === '市辖区') continue;
    if (text.indexOf(nm) >= 0) { g.c = i; break; }
  }
  if (g.c < 0 && cities.length === 1 && cities[0].n === '市辖区') g.c = 0;
  if (g.c < 0) return g;
  const ds = cities[g.c].d || [];
  for (let i = 0; i < ds.length; i++) {
    if (ds[i] !== '市辖区' && text.indexOf(ds[i]) >= 0) { g.d = i; break; }
  }
  return g;
}

export function openRegionPicker(current, onPick) {
  const wrap = h('div', 'rg-box');
  wrap.appendChild(h('div', 'sp-empty', '正在加载地区数据…'));
  openSheet('选择住址', wrap, { size: 'sm' });

  loadRegions().then(function (list) {
    wrap.textContent = '';
    wrap.appendChild(h('div', 'rg-tip', '选到区/县就行，乡镇街道可以不填。'));

    const g = guess(list, current);

    const provSel = makeSelect('rg-prov');
    fillSelect(provSel, list.map(function (p) { return p.n; }), '省份');
    if (g.p >= 0) provSel.value = String(g.p);

    const citySel = makeSelect('rg-city');
    const distSel = makeSelect('rg-dist');
    const street = document.createElement('input');
    street.className = 'tb rg-street';
    street.type = 'text';
    street.maxLength = 20;
    street.placeholder = '乡镇 / 街道（可不填）';

    const preview = h('div', 'rg-preview');
    const row1 = h('div', 'rg-row');
    row1.appendChild(provSel);
    row1.appendChild(citySel);
    const row2 = h('div', 'rg-row');
    row2.appendChild(distSel);
    row2.appendChild(street);

    function province() {
      const idx = parseInt(provSel.value, 10);
      return isNaN(idx) || idx < 0 || !list[idx] ? null : list[idx];
    }
    function city() {
      const p = province();
      const ci = parseInt(citySel.value, 10);
      return p && !isNaN(ci) && ci >= 0 ? p.c[ci] : null;
    }
    function district() {
      const c = city();
      const di = parseInt(distSel.value, 10);
      return c && !isNaN(di) && di >= 0 ? c.d[di] : '';
    }
    function picked() {
      const p = province();
      const c = city();
      return {
        prov: p ? p.n : '',
        city: c ? c.n : '',
        dist: district(),
        street: street.value
      };
    }
    function paint() {
      const v = picked();
      preview.textContent = joinAddress(v.prov, v.city, v.dist, v.street) || '还没选';
    }

    function refreshCities() {
      const p = province();
      fillSelect(citySel, p ? p.c.map(function (c) { return c.n; }) : [], '城市');
      if (p && g.p === parseInt(provSel.value, 10) && g.c >= 0 && citySel.options.length > g.c + 1) {
        citySel.value = String(g.c);
      } else if (p && p.c.length === 1) {
        citySel.value = '0';
      }
      refreshDists();
    }

    function refreshDists() {
      const c = city();
      fillSelect(distSel, c ? c.d : [], '区 / 县');
      if (c && c.d.length === 1 && c.d[0] === '市辖区') {
        // 只有「市辖区」的市：不用选，地址里也不会写出来
        distSel.value = '0';
      } else if (c && g.p === parseInt(provSel.value, 10) && g.c === parseInt(citySel.value, 10)
        && g.d >= 0 && distSel.options.length > g.d + 1) {
        distSel.value = String(g.d);
      }
      paint();
    }

    provSel.addEventListener('change', function () {
      g.p = -1; g.c = -1; g.d = -1;
      // 换省以后旧的乡镇街道就不属于这里了，清掉免得拼出「北京市粤海街道」
      street.value = '';
      refreshCities();
    });
    citySel.addEventListener('change', function () {
      g.c = -1; g.d = -1;
      street.value = '';
      refreshDists();
    });
    distSel.addEventListener('change', paint);
    street.addEventListener('input', paint);

    wrap.appendChild(row1);
    wrap.appendChild(row2);
    wrap.appendChild(preview);

    const foot = h('div', 'rg-acts');
    foot.appendChild(btn('清空', 'btn btn-ghost', function () {
      closeSheet();
      onPick('');
    }));
    foot.appendChild(btn('确定', 'btn btn-primary', function () {
      const v = picked();
      if (!v.prov) { toast('先选省份'); return; }
      if (!v.dist && !String(v.street || '').trim()) { toast('选到区/县，或者填上乡镇街道'); return; }
      const text = joinAddress(v.prov, v.city, v.dist, v.street);
      if (text.length > 80) { toast('住址太长了，简要写'); return; }
      closeSheet();
      onPick(text);
    }));
    wrap.appendChild(foot);

    refreshCities();

    // 街道：把现有地址里区县后面的部分回填进来
    if (current) {
      const v = picked();
      const head = joinAddress(v.prov, v.city, v.dist, '');
      const tail = head ? String(current).slice(head.length) : '';
      if (tail && tail.length <= 20) {
        street.value = tail;
        paint();
      }
    }
  }).catch(function (e) {
    wrap.textContent = '';
    wrap.appendChild(h('div', 'sp-empty', String((e && e.message) || '地区数据加载失败')));
  });
}
