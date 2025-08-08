(function($){
  const cfg = window.CARROON_FIPE_LOCAL;
  if (!cfg || !cfg.base) return;

  const f = cfg.fields;
  const $tipo   = $(`[name="${f.tipo}"]`);
  const $marca  = $(`[name="${f.marca}"]`);
  const $marcaN = $(`[name="${f.marca_nome}"]`);
  const $modelo = $(`[name="${f.modelo}"]`);
  const $modeloN= $(`[name="${f.modelo_nome}"]`);
  const $ano    = $(`[name="${f.ano}"]`);
  const $preco  = $(`[name="${f.preco_medio}"]`);
  const $mes    = $(`[name="${f.mes_referencia}"]`);
  const $reg    = f.regular_price ? $(`[name="${f.regular_price}"]`) : $();

  function opt(v,t){ return `<option value="${String(v)}">${t}</option>`; }
  function reset($el,label){ $el.prop('disabled',true).html(opt('',label||'Selecione')); }
  function brToDecimal(str){ if(!str) return ''; return String(str).replace(/[^\d,]/g,'').replace(/\./g,'').replace(',', '.'); }
  async function jget(url){ const r = await fetch(url,{cache:'no-store'}); if(!r.ok) return []; return r.json(); }
  function currentType(){ return $tipo.filter(':checked').val() || 'carros'; }
  function setIfHas($el, val){
    if (!val) return false;
    const $opt = $el.find(`option[value="${val}"]`);
    if ($opt.length){ $el.val(val).trigger('change'); return true; }
    return false;
  }

  async function loadMarcas(preselect){
    reset($marca,'Carregando…'); reset($modelo,'Selecione a Marca'); reset($ano,'Selecione Modelo');
    $preco.val(''); $mes.val(''); if ($reg.length) $reg.val('');
    const data = await jget(`${cfg.base}/marcas?type=${encodeURIComponent(currentType())}`);
    if (!Array.isArray(data) || !data.length){ reset($marca,'Sem marcas'); return; }
    $marca.html(opt('','Selecione a Marca') + data.map(m => opt(m.codigo, m.nome)).join('')).prop('disabled',false);

    if (preselect && setIfHas($marca, preselect)) {
      $marcaN.val($marca.find(':selected').text() || '');
    }
  }

  async function loadModelos(brand, preselect){
    reset($modelo,'Carregando…'); reset($ano,'Selecione Modelo');
    const data = await jget(`${cfg.base}/modelos?type=${encodeURIComponent(currentType())}&brand=${encodeURIComponent(brand)}`);
    if (!Array.isArray(data) || !data.length){ reset($modelo,'Sem modelos'); return; }
    $modelo.html(opt('','Selecione o Modelo') + data.map(m => opt(m.codigo, m.nome)).join('')).prop('disabled',false);

    if (preselect) setIfHas($modelo, preselect);
  }

  async function loadAnos(brand, model, preselect){
    reset($ano,'Carregando…');
    const data = await jget(`${cfg.base}/anos?type=${encodeURIComponent(currentType())}&brand=${encodeURIComponent(brand)}&model=${encodeURIComponent(model)}`);
    if (!Array.isArray(data) || !data.length){ reset($ano,'Sem anos'); return; }
    const options = data.map(a => {
      const code  = String(a.codigo ?? '');
      const label = code.split('-')[0]; // só o ano
      return opt(code, label);
    });
    $ano.html(opt('','Selecione o Ano') + options.join('')).prop('disabled',false);

    if (preselect) setIfHas($ano, preselect);
  }

  async function loadPreco(brand, model, year){
    const r = await jget(`${cfg.base}/preco?type=${encodeURIComponent(currentType())}&brand=${encodeURIComponent(brand)}&model=${encodeURIComponent(model)}&year=${encodeURIComponent(year)}`);
    if (!r) return;
    $preco.val(r.valor || ''); 
    $mes.val(r.mes || '');
    const dec = brToDecimal(r.valor);
    if ($reg.length && dec) $reg.val(dec);
  }

  // Espera o JetForm aplicar o preset (até 2s)
  function waitPreset(){
    return new Promise(resolve=>{
      let tries = 0;
      const t = setInterval(()=>{
        const has = ($(`[name="${f.marca}"]`).val() || $(`[name="${f.modelo}"]`).val() || $(`[name="${f.ano}"]`).val());
        if (has || tries++ > 20){ clearInterval(t); resolve(); }
      }, 100);
    });
  }

  // binds de mudança ao vivo
  $(document).on('change', `[name="${f.marca}"]`, function(){
    const code = $(this).val();
    const name = $(this).find(':selected').text();
    $marcaN.val(name||'');
    if (code) loadModelos(code);
    else { reset($modelo,'Selecione a Marca'); reset($ano,'Selecione Modelo'); $preco.val(''); $mes.val(''); if ($reg.length) $reg.val(''); }
  });

  $(document).on('change', `[name="${f.modelo}"]`, function(){
    const code = $(this).val();
    const name = $(this).find(':selected').text();
    $modeloN.val(name||'');
    if (code) loadAnos($marca.val(), code);
    else { reset($ano,'Selecione Modelo'); $preco.val(''); $mes.val(''); if ($reg.length) $reg.val(''); }
  });

  $(document).on('change', `[name="${f.ano}"]`, function(){
    const code = $(this).val(); // ex: 2023-5
    if (code) loadPreco($marca.val(), $modelo.val(), code);
  });

  // init (create + edit)
  $(async function(){
    await waitPreset(); // garante que __marca/__modelo/__ano_modelo vieram do preset

    const preBrand = $marca.val()  || '';
    const preModel = $modelo.val() || '';
    const preYear  = $ano.val()    || '';

    // carrega em ordem: marca -> modelos -> anos
    await loadMarcas(preBrand || null);
    if (preBrand){
      await loadModelos(preBrand, preModel || null);
      if (preModel){
        await loadAnos(preBrand, preModel, preYear || null);
        if (preYear) loadPreco(preBrand, preModel, preYear);
      }
    }

    // se trocar o tipo, recarrega tudo
    if ($tipo.length){
      $tipo.on('change', async ()=> {
        await loadMarcas(null);
      });
    }
  });
})(jQuery);

// Formatação de preço/quilometragem + sincroniza ano_fabricacao
(function($){
  function onlyDigits(s){ return (s||'').replace(/\D+/g,''); }
  function moneyBR(v){
    let s = onlyDigits(v); if (s==='') return '';
    while (s.length<3) s = '0'+s;
    const cents = s.slice(-2);
    let int = s.slice(0,-2).replace(/^0+/, '') || '0';
    int = int.replace(/\B(?=(\d{3})+(?!\d))/g,'.');
    return int+','+cents;
  }
  function intBR(v){ let s=onlyDigits(v); s=s.replace(/^0+/, '')||'0'; return s.replace(/\B(?=(\d{3})+(?!\d))/g,'.'); }
  function toWooPrice(s){ return s ? s.replace(/\./g,'').replace(',', '.') : ''; }
  function unformatInt(s){ return onlyDigits(s); }

  const $price = $('[name="_regular_price"]');
  const $price2= $('[name="_price"]');
  const $km    = $('[name="_quilometragem"]');

  function bindMoney($el){ if(!$el.length) return; $el.on('input blur', function(){ this.value = moneyBR(this.value); }); }
  function bindInt($el){ if(!$el.length) return; $el.on('input blur', function(){ this.value = intBR(this.value); }); }

  bindMoney($price); bindMoney($price2); bindInt($km);

  $(document).on('submit','form.jet-form-builder, form.jet-form', function(){
    if ($price.length){ const norm = toWooPrice($price.val()); $price.val(norm); if($price2.length) $price2.val(norm); }
    if ($km.length){ $km.val(unformatInt($km.val())); }
  });

  // ano_fabricacao = 4 primeiros de __ano_modelo
  const $anoCode  = $('[name="__ano_modelo"]');
  const $anoLabel = $('[name="ano_de_fabricacao"]');
  function syncAno(){ const year=(String($anoCode.val()||'').split('-')[0]||'').slice(0,4); if($anoLabel.length) $anoLabel.val(year); }
  $(document).on('change input','[name="__ano_modelo"]', syncAno);
  $(syncAno);
})(jQuery);
