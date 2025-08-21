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
    const $anoN   = $(`[name="${f.ano_rotulo}"]`);
    const $preco  = $(`[name="${f.preco_medio}"]`);
    const $mes    = $(`[name="${f.mes_referencia}"]`);
    
    // --- Variáveis de formatação ---
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
    function currentType(){ return $tipo.filter(':checked').val() || 'carros'; }
    
    async function jget(url) {
        try {
            const r = await fetch(url, { cache: 'no-store' });
            if (!r.ok) {
                console.error(`Falha na API FIPE para ${url} com status ${r.status}`);
                return [];
            }
            return await r.json();
        } catch (error) {
            console.error(`Erro de rede ou JSON inválido para ${url}:`, error);
            return [];
        }
    }
    
    // --- FUNÇÕES DE CARREGAMENTO (API) ---
    async function loadMarcas(){
        reset($marca,'Carregando…'); reset($modelo,'Selecione a Marca'); reset($ano,'Selecione o Modelo');
        $preco.val(''); $mes.val('');
        const data = await jget(`${cfg.base}/marcas?type=${encodeURIComponent(currentType())}`);
        if (!Array.isArray(data) || !data.length){ reset($marca,'Sem marcas'); return; }
        $marca.html(opt('','Selecione a Marca') + data.map(m => opt(m.codigo, m.nome)).join('')).prop('disabled',false);
       //taylo mexeu aqui
        if($marca.data("default-val")){
            $marca.val($marca.data("default-val"));
            setTimeout(function() {
                loadModelos($marca.val());
            }, 100);
        }
        //taylo mexeu aqui
    }

    async function loadModelos(brand){
        reset($modelo,'Carregando…'); reset($ano,'Selecione o Modelo');
        const data = await jget(`${cfg.base}/modelos?type=${encodeURIComponent(currentType())}&brand=${encodeURIComponent(brand)}`);
        if (!Array.isArray(data) || !data.length){ reset($modelo,'Sem modelos'); return; }
        $modelo.html(opt('','Selecione o Modelo') + data.map(m => opt(m.codigo, m.nome)).join('')).prop('disabled',false);
        if($modelo.data("default-val")){
            $modelo.val($modelo.data("default-val"));
            setTimeout(function() {
                loadAnos($marca.val(),$modelo.val());
            }, 100);
        }
    }

    async function loadAnos(brand, model){
        reset($ano,'Carregando…');
        const data = await jget(`${cfg.base}/anos?type=${encodeURIComponent(currentType())}&brand=${encodeURIComponent(brand)}&model=${encodeURIComponent(model)}`);
        if (!Array.isArray(data) || !data.length){ reset($ano,'Sem anos'); return; }
        $ano.html(opt('','Selecione o Ano') + data.map(a => opt(a.codigo, a.nome)).join('')).prop('disabled',false);
        if($ano.data("default-val")){
            $ano.val($ano.data("default-val"));
            setTimeout(function() {
                loadPreco($marca.val(),$modelo.val(),$ano.val());
            }, 100);
        }
    }

    async function loadPreco(brand, model, year){
        const r = await jget(`${cfg.base}/preco?type=${encodeURIComponent(currentType())}&brand=${encodeURIComponent(brand)}&model=${encodeURIComponent(model)}&year=${encodeURIComponent(year)}`);
        if (!r) return;
        $preco.val(r.valor || ''); 
        $mes.val(r.mes || '');
    }

    // --- EVENTOS DE MUDANÇA (INTERAÇÃO DO USUÁRIO) ---
    $(document).on('change', `[name="${f.tipo}"]`, loadMarcas);

    $(document).on('change', `[name="${f.marca}"]`, function(){
        const code = $(this).val();
        $marcaN.val($(this).find(':selected').text()||'');
        if (code) loadModelos(code);
        else { reset($modelo,'Selecione a Marca'); reset($ano,'Selecione o Modelo'); }
    });

    $(document).on('change', `[name="${f.modelo}"]`, function(){
        const code = $(this).val();
        $modeloN.val($(this).find(':selected').text()||'');
        if (code) loadAnos($marca.val(), code);
        else reset($ano,'Selecione o Modelo');
    });

    $(document).on('change', `[name="${f.ano}"]`, function(){
        const code = $(this).val();
        $anoN.val($(this).find(':selected').text() || '');
        if (code) loadPreco($marca.val(), $modelo.val(), code);
    });
    
    // --- LÓGICA DE INICIALIZAÇÃO (AO CARREGAR A PÁGINA) ---
    $(async function(){
        try {
            const saved = cfg.saved || {};

            // 1. Define o tipo de veículo se estiver salvo
            if (saved.type) {
                $tipo.filter(`[value="${saved.type}"]`).prop('checked', true);
            }

            // 2. Sempre carrega as marcas para o tipo selecionado (padrão ou salvo)
            await loadMarcas();

            // 3. Se não houver marca salva, é um formulário de criação. Fim.
            if (!saved.brand) return;

            // --- Lógica de Edição (continua se houver marca salva) ---

            // 4. Seleciona a marca salva
            $marca.val(saved.brand);
            if ($marca.val() !== saved.brand) return;
            $marcaN.val($marca.find(':selected').text() || '');

            // 5. Carrega e seleciona o modelo salvo
            await loadModelos(saved.brand);
            $modelo.val(saved.model);
            if ($modelo.val() !== saved.model) return;
            $modeloN.val($modelo.find(':selected').text() || '');

            // 6. Carrega e seleciona o ano salvo
            await loadAnos(saved.brand, saved.model);
            $ano.val(saved.year);
            if ($ano.val() !== saved.year) return;
            $anoN.val($ano.find(':selected').text() || '');
            
            // 7. Carrega o preço
            await loadPreco(saved.brand, saved.model, saved.year);

        } catch (error) {
            console.error("Erro na inicialização do formulário FIPE:", error);
        }
    });

    // --- FORMATAÇÃO DE CAMPOS DE PREÇO E KM ---
    function bindMoney($el){ if(!$el.length) return; $el.on('input blur', function(){ this.value = moneyBR(this.value); }); }
    function bindInt($el){ if(!$el.length) return; $el.on('input blur', function(){ this.value = intBR(this.value); }); }

    bindMoney($price); 
    bindMoney($price2); 
    bindInt($km);
    
    // Formata valores iniciais se já existirem
    if ($price.length && $price.val()) $price.val(moneyBR($price.val()));
    if ($price2.length && $price2.val()) $price2.val(moneyBR($price2.val()));

    // Remove formatação antes de enviar o formulário
    $(document).on('submit','form.jet-form-builder, form.jet-form', function(){
        if ($price.length){ const norm = toWooPrice($price.val()); $price.val(norm); if($price2.length) $price2.val(norm); }
        if ($km.length){ $km.val(unformatInt($km.val())); }
    });

})(jQuery);
