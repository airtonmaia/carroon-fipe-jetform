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
    
    // --- Variáveis e funções de formatação ---
    const $price = $('[name="_regular_price"]');
    const $price2= $('[name="_price"]');
    const $km    = $('[name="_quilometragem"]');

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

    // --- FUNÇÕES AUXILIARES ---
    function opt(v,t){ return `<option value="${String(v)}">${t}</option>`; }
    function reset($el,label){ $el.prop('disabled',true).html(opt('',label||'Selecione')); }
    function brToDecimal(str){ if(!str) return ''; return String(str).replace(/[^\d,]/g,'').replace(/\./g,'').replace(',', '.'); }
    async function jget(url){ const r = await fetch(url,{cache:'no-store'}); if(!r.ok) return []; return r.json(); }
    function currentType(){ return $tipo.filter(':checked').val() || 'carros'; }
    
    // --- FUNÇÕES DE CARREGAMENTO (API) ---
    async function loadMarcas(){
        reset($marca,'Carregando…'); reset($modelo,'Selecione a Marca'); reset($ano,'Selecione o Modelo');
        $preco.val(''); $mes.val(''); if ($reg.length) $reg.val('');
        const data = await jget(`${cfg.base}/marcas?type=${encodeURIComponent(currentType())}`);
        if (!Array.isArray(data) || !data.length){ reset($marca,'Sem marcas'); return; }
        $marca.html(opt('','Selecione a Marca') + data.map(m => opt(m.codigo, m.nome)).join('')).prop('disabled',false);
    }

    async function loadModelos(brand){
        reset($modelo,'Carregando…'); reset($ano,'Selecione o Modelo');
        const data = await jget(`${cfg.base}/modelos?type=${encodeURIComponent(currentType())}&brand=${encodeURIComponent(brand)}`);
        if (!Array.isArray(data) || !data.length){ reset($modelo,'Sem modelos'); return; }
        $modelo.html(opt('','Selecione o Modelo') + data.map(m => opt(m.codigo, m.nome)).join('')).prop('disabled',false);
    }

    async function loadAnos(brand, model){
        reset($ano,'Carregando…');
        const data = await jget(`${cfg.base}/anos?type=${encodeURIComponent(currentType())}&brand=${encodeURIComponent(brand)}&model=${encodeURIComponent(model)}`);
        if (!Array.isArray(data) || !data.length){ reset($ano,'Sem anos'); return; }
        const options = data.map(a => {
            const code  = String(a.codigo ?? '');
            const label = code.split('-')[0]; // só o ano
            return opt(code, label);
        });
        $ano.html(opt('','Selecione o Ano') + options.join('')).prop('disabled',false);
    }

    async function loadPreco(brand, model, year){
        const r = await jget(`${cfg.base}/preco?type=${encodeURIComponent(currentType())}&brand=${encodeURIComponent(brand)}&model=${encodeURIComponent(model)}&year=${encodeURIComponent(year)}`);
        if (!r) return;
        $preco.val(r.valor || ''); 
        $mes.val(r.mes || '');
        const dec = brToDecimal(r.valor);
        if ($reg.length && dec) $reg.val(dec);
    }

    // --- EVENTOS DE MUDANÇA (PARA INTERAÇÃO DO USUÁRIO) ---
    $(document).on('change', `[name="${f.marca}"]`, function(){
        const code = $(this).val();
        $marcaN.val($(this).find(':selected').text()||'');
        if (code) {
            loadModelos(code);
        } else { 
            reset($modelo,'Selecione a Marca'); reset($ano,'Selecione o Modelo'); $preco.val(''); $mes.val(''); if ($reg.length) $reg.val(''); 
        }
    });

    $(document).on('change', `[name="${f.modelo}"]`, function(){
        const code = $(this).val();
        $modeloN.val($(this).find(':selected').text()||'');
        if (code) {
            loadAnos($marca.val(), code);
        } else { 
            reset($ano,'Selecione o Modelo'); $preco.val(''); $mes.val(''); if ($reg.length) $reg.val(''); 
        }
    });

    $(document).on('change', `[name="${f.ano}"]`, function(){
        const code = $(this).val();
        if (code) loadPreco($marca.val(), $modelo.val(), code);
    });
    
    $(document).on('change', `[name="${f.tipo}"]`, function(){
        loadMarcas();
    });

    // --- INICIALIZAÇÃO (CRIAR E EDITAR) - VERSÃO FINAL E ROBUSTA ---
    $(async function(){
        const saved = cfg.saved || {};
        const preBrand = saved.brand || '';
        const preModel = saved.model || '';
        const preYear  = saved.year  || '';
        const preType  = saved.type  || '';

        if (!preBrand) {
            if (preType) {
                $tipo.filter(`[value="${preType}"]`).prop('checked', true);
            }
            await loadMarcas();
            return;
        }

        if (preType) {
            $tipo.filter(`[value="${preType}"]`).prop('checked', true);
        }
        
        await loadMarcas();
        $marca.val(preBrand);
        if ($marca.val() !== preBrand) return;
        $marcaN.val($marca.find(':selected').text() || '');

        await loadModelos(preBrand);
        $modelo.val(preModel);
        if ($modelo.val() !== preModel) return;
        $modeloN.val($modelo.find(':selected').text() || '');

        await loadAnos(preBrand, preModel);
        $ano.val(preYear);
        if ($ano.val() !== preYear) return;
        
        await loadPreco(preBrand, preModel, preYear);

    });

    // --- BINDING FINAL DOS EVENTOS DE FORMATAÇÃO ---
    function bindMoney($el){ if(!$el.length) return; $el.on('input blur', function(){ this.value = moneyBR(this.value); }); }
    function bindInt($el){ if(!$el.length) return; $el.on('input blur', function(){ this.value = intBR(this.value); }); }

    bindMoney($price); 
    bindMoney($price2); 
    bindInt($km);
    
    $(document).on('submit','form.jet-form-builder, form.jet-form', function(){
        if ($price.length){ const norm = toWooPrice($price.val()); $price.val(norm); if($price2.length) $price2.val(norm); }
        if ($km.length){ $km.val(unformatInt($km.val())); }
    });

})(jQuery);
