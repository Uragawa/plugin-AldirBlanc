<?php 
use MapasCulturais\i;

$routeCNAB240BB = MapasCulturais\App::i()->createUrl('remessas', 'exportCnab240Inciso3',['opportunity' => $opportunity]); 
if ($inciso == 1) {
    $routedataPrevCsv = MapasCulturais\App::i()->createUrl('dataprev', 'export_inciso1');
    $routeCNAB240BB = MapasCulturais\App::i()->createUrl('remessas', 'exportCnab240Inciso1',['opportunity' => $opportunity]); 
    ?>
    <a class="btn btn-primary btn-export-cancel"  ng-click="editbox.open('export-inciso1', $event)" rel="noopener noreferrer">CSV DataPrev</a>
    <?php if ($qtdSelected) { ?>
    <?php } ?>

    <!-- Formulário -->
    <edit-box id="export-inciso1" position="top" title="<?php i::esc_attr_e('Exportar csv Inciso 1') ?>" cancel-label="Cancelar" close-on-cancel="true">
        <form class="form-export-dataprev" action="<?=$routedataPrevCsv?>" method="POST">
      
            <label for="from">Data inícial</label>
            <input type="date" name="from" id="from">
            
            <label for="from">Data final</label>  
            <input type="date" name="to" id="to">

            # Caso não queira filtrar entre datas, deixe os campos vazios.
            <button class="btn btn-primary download" type="submit">Exportar</button>
        </form>
    </edit-box>

    <?php
}
else if ($inciso == 2){
    $routedataPrevCsv = MapasCulturais\App::i()->createUrl('dataprev', 'export_inciso2');
    $routeGenCsv = MapasCulturais\App::i()->createUrl('remessas', 'genericExportInciso2',['opportunity' => $opportunity]); 
    $routeCNAB240BB = MapasCulturais\App::i()->createUrl('remessas', 'exportCnab240Inciso2',['opportunity' => $opportunity]); 

    ?>
    <a class="btn btn-primary form-export-clear" ng-click="editbox.open('export-inciso2', $event)" rel="noopener noreferrer">CSV DataPrev</a>
    
    <?php if($qtdSelected){?>
        <a href="<?= $routeGenCsv?>"  class="btn btn-primary download"  rel="noopener noreferrer">CSV Prodam</a>
    <?php } ?>
    
    <!-- Formulario para cpf -->
    <edit-box id="export-inciso2" position="top" title="<?php i::esc_attr_e('Exportar csv Inciso 2') ?>" cancel-label="Cancelar" close-on-cancel="true">
        <form class="form-export-dataprev" action="<?=$routedataPrevCsv?>" method="POST">
      
            <label for="from">Data inícial</label>
            <input type="date" name="from" id="from">
            
            <label for="from">Data final</label>  
            <input type="date" name="to" id="to">            

            <label for="type">Tipo de exportação (CPF ou CNPJ)</label>
            <select name="type" id="type">
                <option value="cpf">Pessoa física (CPF)</option>
                <option value="cnpj">Pessoa jurídica (CNPJ)</option>
            </select>

            <input type="hidden" name="opportunity" value="<?=$opportunity?>">

            # Caso não queira filtrar entre datas, deixe os campos vazios.
            <button class="btn btn-primary download" type="submit">Exportar</button>            
        </form>
    </edit-box>

    <?php
}
if ($qtdSelected) { ?>
    <a class="btn btn-primary form-export-clear" ng-click="editbox.open('export-cnab240-bb', $event)" rel="noopener noreferrer">CNAB240 BB</a>
    <!-- Formulario para BB -->
    <edit-box id="export-cnab240-bb" position="top" title="<?php i::esc_attr_e("Exportar CNAB240-BB Inciso " . $inciso) ?>" cancel-label="Cancelar" close-on-cancel="true">
        <form class="form-export-cnab240-bb" action="<?=$routeCNAB240BB?>" method="POST">
        
            <label for="from">Data inícial</label>
            <input type="date" name="from" id="from">
                
            <label for="from">Data final</label>  
            <input type="date" name="to" id="to">            

            <input type="hidden" name="opportunity" value="<?=$opportunity?>">

            # Caso não queira filtrar entre datas, deixe os campos vazios.
            <button class="btn btn-primary download" type="submit">Exportar</button>            
        </form>
    </edit-box>
<?php
} ?>
