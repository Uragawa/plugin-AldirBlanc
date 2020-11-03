<?php

namespace AldirBlanc\Controllers;

use DateInterval;
use DateTime;
use Exception;
use League\Csv\Writer;
use MapasCulturais\App;
use MapasCulturais\Entities\Registration;
use MapasCulturais\i;
use Normalizer;

/**
 * Registration Controller
 *
 * By default this controller is registered with the id 'registration'.
 *
 *  @property-read \MapasCulturais\Entities\Registration $requestedEntity The Requested Entity
 */
// class AldirBlanc extends \MapasCulturais\Controllers\EntityController {
class Remessas extends \MapasCulturais\Controllers\Registration
{
    const VALIDATION_MISSING_DATA = -2;
    const VALIDATION_NORULE = -1;
    const VALIDATION_PASSED = 0;
    const VALIDATION_FAILED_BRANCH = 1;
    const VALIDATION_FAILED_ACCOUNT = 2;
    const VALIDATION_FAILED_OPERATION = 4;

    protected $config = [];

    public function __construct()
    {
        parent::__construct();

        $app = App::i();

        $this->config = $app->plugins['AldirBlanc']->config;
        $this->entityClassName = '\MapasCulturais\Entities\Registration';
        $this->layout = 'aldirblanc';
    }

    protected function filterRegistrations(array $registrations) {
        $app = App::i();

        $_regs = [];
        foreach ($registrations as $registration) {
            if ($this->config['exportador_requer_homologacao']) {
                if (in_array($registration->consolidatedResult, ['10', 'homologado']) ) {
                    $_regs[] = $registration;
                }
            } else {
                $_regs[] = $registration;
            }
        }

        return $_regs;
    }

    /**
     * Implementa o exportador TXT no modelo CNAB 240, para envio de remessas ao banco do Brasil inciso1
     *
     *
     */
    public function ALL_exportCnab240Inciso1()
    {
        //Seta o timeout
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '768M');

        /**
         * Verifica se o usuário está autenticado
         */
        $this->requireAuthentication();
        $app = App::i();

        $getData = false;
        if (!empty($this->data)) {

            if (isset($this->data['from']) && isset($this->data['to'])) {

                if (!empty($this->data['from']) && !empty($this->data['to'])) {
                    if (!preg_match("/^[0-9]{4}-[0-9]{1,2}-[0-9]{1,2}$/", $this->data['from']) ||
                        !preg_match("/^[0-9]{4}-[0-9]{1,2}-[0-9]{1,2}$/", $this->data['to'])) {

                        throw new \Exception("O formato da data é inválido.");

                    } else {
                        //Data ínicial
                        $startDate = new DateTime($this->data['from']);
                        $startDate = $startDate->format('Y-m-d 00:00');

                        //Data final
                        $finishDate = new DateTime($this->data['to']);
                        $finishDate = $finishDate->format('Y-m-d 23:59');
                    }

                    $getData = true;
                }

            }
        }

        //Pega a oportunidade no array de config
        $opportunity_id = $this->config['inciso1_opportunity_id'];

        /**
         * Pega informações da oportunidade
         */
        $opportunity = $app->repo('Opportunity')->find($opportunity_id);
        $this->registerRegistrationMetadata($opportunity);

        if (!$opportunity->canUser('@control')) {
            echo "Não autorizado";
            die();
        }

        /**
         * Pega os dados das configurações
         */
        $txt_config = $this->config['config-cnab240-inciso1'];
        $default = $txt_config['parameters_default'];
        $header1 = $txt_config['HEADER1'];
        $header2 = $txt_config['HEADER2'];
        $detahe1 = $txt_config['DETALHE1'];
        $detahe2 = $txt_config['DETALHE2'];
        $trailer1 = $txt_config['TRAILER1'];
        $trailer2 = $txt_config['TRAILER2'];

        /**
         * Busca as inscrições com status 10 (Selecionada)
         */
        if ($getData) {
            $dql = "SELECT e FROM MapasCulturais\Entities\Registration e
            WHERE e.status = 1 AND
            e.opportunity = :opportunity_Id AND
            e.sentTimestamp >=:startDate AND
            e.sentTimestamp <= :finishDate";

            $query = $app->em->createQuery($dql);
            $query->setParameters([
                'opportunity_Id' => $opportunity_id,
                'startDate' => $startDate,
                'finishDate' => $finishDate,
            ]);

            $registrations = $query->getResult();

        } else {
            $dql = "SELECT e FROM MapasCulturais\Entities\Registration e
            WHERE e.status = 1 AND
            e.opportunity = :opportunity_Id";

            $query = $app->em->createQuery($dql);
            $query->setParameters([
                'opportunity_Id' => $opportunity_id,
            ]);

            $registrations = $query->getResult();

        }

        $this->filterRegistrations($registrations);

        if (empty($registrations)) {
            echo "Não foram encontrados registros.";
            die();
        }

        $mappedHeader1 = [
            'BANCO' => '',
            'LOTE' => '',
            'REGISTRO' => '',
            'USO_BANCO_12' => '',
            'INSCRICAO_TIPO' => '',
            'CPF_CNPJ_FONTE_PAG' => '',
            'CONVENIO_BB1' => '',
            'CONVENIO_BB2' => '',
            'CONVENIO_BB3' => '',
            'CONVENIO_BB4' => '',
            'AGENCIA' => function ($registrations) use ($header1) {
                $result = "";
                $field_id = $header1['AGENCIA'];
                $value = $this->normalizeString($field_id['default']);
                return substr($value, 0, 4);

            },
            'AGENCIA_DIGITO' => function ($registrations) use ($header1) {
                $result = "";
                $field_id = $header1['AGENCIA_DIGITO'];
                $value = $this->normalizeString($field_id['default']);
                $result = is_string($value) ? strtoupper($value) : $value;
                return $result;

            },
            'CONTA' => function ($registrations) use ($header1) {
                $result = "";
                $field_id = $header1['CONTA'];
                $value = $this->normalizeString($field_id['default']);
                return substr($value, 0, 12);


            },
            'CONTA_DIGITO' => function ($registrations) use ($header1) {
                $result = "";
                $field_id = $header1['CONTA_DIGITO'];
                $value = $this->normalizeString($field_id['default']);
                $result = is_string($value) ? strtoupper($value) : $value;
                return $result;

            },
            'USO_BANCO_20' => '',
            'NOME_EMPRESA' => function ($registrations) use ($header1, $app) {
                $result =  $header1['NOME_EMPRESA']['default'];
                return substr($result, 0, 30);
            },
            'NOME_BANCO' => '',
            'USO_BANCO_23' => '',
            'CODIGO_REMESSA' => '',
            'DATA_GER_ARQUIVO' => function ($registrations) use ($detahe1) {
                $date = new DateTime();
                return $date->format('dmY');
            },
            'HORA_GER_ARQUIVO' => function ($registrations) use ($detahe1) {
                $date = new DateTime();
                return $date->format('His');
            },
            'NUM_SERQUNCIAL_ARQUIVO' => '',
            'LAYOUT_ARQUIVO' => '',
            'DENCIDADE_GER_ARQUIVO' => '',
            'USO_BANCO_30' => '',
            'USO_BANCO_31' => '',
        ];

        $mappedHeader2 = [
            'BANCO' => '',
            'LOTE' => '',
            'REGISTRO' => '',
            'OPERACAO' => '',
            'SERVICO' => '',
            'FORMA_LANCAMENTO' => '',
            'LAYOUT_LOTE' => '',
            'USO_BANCO_43' => '',
            'INSCRICAO_TIPO' => '',
            'INSCRICAO_NUMERO' => '',
            'CONVENIO_BB1' => '',
            'CONVENIO_BB2' => '',
            'CONVENIO_BB3' => '',
            'CONVENIO_BB4' => '',
            'AGENCIA' => function ($registrations) use ($header2) {
                $result = "";
                $field_id = $header2['AGENCIA'];
                $value = $this->normalizeString($field_id['default']);
                return substr($value, 0, 4);

            },
            'AGENCIA_DIGITO' => function ($registrations) use ($header2) {
                $result = "";
                $field_id = $header2['AGENCIA_DIGITO'];
                $value = $this->normalizeString($field_id['default']);
                $result = is_string($value) ? strtoupper($value) : $value;
                return $result;

            },
            'CONTA' => function ($registrations) use ($header2) {
                $result = "";
                $field_id = $header2['CONTA'];
                $value = $this->normalizeString($field_id['default']);
                return substr($value, 0, 12);


            },
            'CONTA_DIGITO' => function ($registrations) use ($header2) {
                $result = "";
                $field_id = $header2['CONTA_DIGITO'];
                $value = $this->normalizeString($field_id['default']);
                $result = is_string($value) ? strtoupper($value) : $value;
                return $result;

            },
            'USO_BANCO_51' => '',
            'NOME_EMPRESA' => function ($registrations) use ($header2, $app) {
                $result =  $header2['NOME_EMPRESA']['default'];
                return substr($result, 0, 30);
            },
            'USO_BANCO_40' => '',
            'LOGRADOURO' => '',
            'NUMERO' => '',
            'COMPLEMENTO' => '',
            'CIDADE' => '',
            'CEP' => '',
            'ESTADO' => '',
            'USO_BANCO_60' => '',
            'USO_BANCO_61' => '',
        ];

        $mappedDeletalhe1 = [
            'BANCO' => '',
            'LOTE' => '',
            'REGISTRO' => '',
            'NUMERO_REGISTRO' => '',
            'SEGMENTO' => '',
            'TIPO_MOVIMENTO' => '',
            'CODIGO_MOVIMENTO' => '',
            'CAMARA_CENTRALIZADORA' => '',
            'BEN_CODIGO_BANCO' => function ($registrations) use ($detahe1) {
                $field_id = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                return $this->numberBank($registrations->$field_id);

            },
            'BEN_AGENCIA' => function ($registrations) use ($detahe1, $default) {

                $temp = $default['formoReceipt'];
                $formoReceipt = $registrations->$temp;

                if($formoReceipt == "CARTEIRA DIGITAL BB"){
                    $field_id = $default['fieldsWalletDigital']['agency'];
                }else{
                    $field_id = $detahe1['BEN_AGENCIA']['field_id'];
                }

                $age = $this->normalizeString($registrations->$field_id);

                if (strlen($age) > 4) {

                    $result = substr($age, 0, 4);
                } else {
                    $result = $age;
                }

                return is_string($result) ? strtoupper($result) : $result;
            },
            'BEN_AGENCIA_DIGITO' => function ($registrations) use ($detahe1, $default) {
                $result = "";

                $temp = $default['formoReceipt'];
                $formoReceipt = $registrations->$temp;

                if($formoReceipt == "CARTEIRA DIGITAL BB"){
                    $field_id = $default['fieldsWalletDigital']['agency'];
                }else{
                    $field_id = $detahe1['BEN_AGENCIA_DIGITO']['field_id'];
                }

                $dig = $this->normalizeString($registrations->$field_id);
                if (strlen($dig) > 4) {
                    $result = substr($dig, -1);
                } else {
                    $result = "";
                }

                return is_string($result) ? strtoupper($result) : $result;
            },
            'BEN_CONTA' => function ($registrations) use ($detahe1, $default, $app) {
                $temp = $default['formoReceipt'];
                $formoReceipt = $registrations->$temp;

                if($formoReceipt == "CARTEIRA DIGITAL BB"){
                    $field_id = $default['fieldsWalletDigital']['account'];
                }else{
                    $field_id = $detahe1['BEN_CONTA_DIGITO']['field_id'];
                }

                $account = $this->normalizeString($registrations->$field_id);

                $temp = $detahe1['TIPO_CONTA']['field_id'];
                $typeAccount = $registrations->$temp;

                $temp = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                if($temp){
                    $numberBank = $this->numberBank($registrations->$temp);
                }else{
                    $numberBank = $default['defaultBank'];
                }

                if(!$account){
                    $app->log->info($registrations->number . " Conta bancária não informada");
                    return " ";
                }

                $result  = "";
                if($typeAccount == $default['typesAccount']['poupanca']){

                    if (($numberBank == '001') && (substr($account, 0, 3) != "510")) {

                        $result = "510" . $account;

                    }else{
                        $result = $account;
                    }
                }else{
                    $result = $account;
                }

                $result = preg_replace('/[^0-9]/i', '',$result);

                if($temp === $field_id){
                    return substr($this->normalizeString($result), 0, -1); // Remove o ultimo caracter. Intende -se que o ultimo caracter é o DV da conta

                }else{
                    return $result;

                }




            },
            'BEN_CONTA_DIGITO' => function ($registrations) use ($detahe1, $default, $app) {
                $temp = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                $field_id = $detahe1['BEN_CONTA']['field_id'];

                if($temp){
                    $numberBank = $this->numberBank($registrations->$temp);
                }else{
                    $numberBank = $default['defaultBank'];
                }

                $temp = $default['formoReceipt'];
                $formoReceipt = $registrations->$temp;

                if($formoReceipt == "CARTEIRA DIGITAL BB"){
                    $temp = $default['fieldsWalletDigital']['account'];
                }else{
                    $temp = $detahe1['BEN_CONTA_DIGITO']['field_id'];
                }

                $account = $this->normalizeString(preg_replace('/[^0-9]/i', '', $registrations->$temp));

                $temp = $detahe1['TIPO_CONTA']['field_id'];
                $typeAccount = $registrations->$temp;

                $dig = substr($account, -1);

                if(!$account && ($temp == $field_id)){
                    $app->log->info($registrations->number . " Conta sem DV");
                    return " ";
                }

                $result = "";

                if ($numberBank == '001' && $typeAccount == $default['typesAccount']['poupanca']) {
                    if (substr($account, 0, 3) == "510") {
                        $result = $dig;
                    } else {

                        $result = $default['savingsDigit'][$dig];

                    }
                } else {

                    $result = $dig;
                }

                return is_string($result) ? strtoupper($result) : $result;

            },
            'BEN_DIGITO_CONTA_AGENCIA_80' => '',
            'BEN_NOME' => function ($registrations) use ($detahe1) {
                $field_id = $detahe1['BEN_NOME']['field_id'];
                $result = substr($this->normalizeString($registrations->$field_id), 0, $detahe1['BEN_NOME']['length']);
                // if($result == "Daniel Dias Victor"){
                //     var_dump($registrations->number);
                //     exit();
                // }
                return $result;
            },
            'BEN_DOC_ATRIB_EMPRESA_82' => '',
            'DATA_PAGAMENTO' => function ($registrations) use ($detahe1) {
                $date = new DateTime();
                $date->add(new DateInterval('P1D'));
                $weekday = $date->format('D');

                $weekdayList = [
                    'Mon' => true,
                    'Tue' => true,
                    'Wed' => true,
                    'Thu' => true,
                    'Fri' => true,
                    'Sat' => false,
                    'Sun' => false,
                ];

                while (!$weekdayList[$weekday]) {
                    $date->add(new DateInterval('P1D'));
                    $weekday = $date->format('D');
                }

                return $date->format('dmY');
            },
            'TIPO_MOEDA' => '',
            'USO_BANCO_85' => '',
            'VALOR_INTEIRO' => function ($registrations) use ($detahe1, $default) {
                $field_id = $default['womanMonoParent'];
                if($registrations->$field_id == "SIM"){
                    $valor = '6000.00';
                }else{
                    $valor = '3000.00';
                }
                $valor = preg_replace('/[^0-9]/i', '', $valor);
                return $valor;
            },
            'USO_BANCO_88' => '',
            'USO_BANCO_89' => '',
            'USO_BANCO_90' => '',
            'CODIGO_FINALIDADE_TED' => function ($registrations) use ($detahe1, $default) {
                $temp = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                if($temp){
                    $numberBank = $this->numberBank($registrations->$temp);
                }else{
                    $numberBank = $default['defaultBank'];
                }
                if ($numberBank != "001") {
                    return '10';
                } else {
                    return "";
                }
            },
            'USO_BANCO_92' => '',
            'USO_BANCO_93' => '',
            'TIPO_CONTA' => '',
        ];

        $mappedDeletalhe2 = [
            'BANCO' => '',
            'LOTE' => '',
            'REGISTRO' => '',
            'NUMERO_REGISTRO' => '',
            'SEGMENTO' => '',
            'USO_BANCO_104' => '',
            'BEN_TIPO_DOC' => '',
            'BEN_CPF' => function ($registrations) use ($detahe2) {
                $field_id = $detahe2['BEN_CPF']['field_id'];
                $data = $registrations->$field_id;
                if (strlen($this->normalizeString($data)) != 11) {
                    $_SESSION['problems'][$registrations->number] = "CPF Inválido";
                }
                return $data;
            },
            'BEN_ENDERECO_LOGRADOURO' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_LOGRADOURO']['field_id'];
                $length = $detahe2['BEN_ENDERECO_LOGRADOURO']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Nome_Logradouro'];

                $result = substr($result, 0, $length);

                return $result;

            },
            'BEN_ENDERECO_NUMERO' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_NUMERO']['field_id'];
                $length = $detahe2['BEN_ENDERECO_NUMERO']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Num'];

                $result = substr($result, 0, $length);

                return $result;
            },
            'BEN_ENDERECO_COMPLEMENTO' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_COMPLEMENTO']['field_id'];
                $length = $detahe2['BEN_ENDERECO_COMPLEMENTO']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Complemento'];

                $result = substr($result, 0, $length);

                return $result;
            },
            'BEN_ENDERECO_BAIRRO' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_BAIRRO']['field_id'];
                $length = $detahe2['BEN_ENDERECO_BAIRRO']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Bairro'];

                $result = substr($result, 0, $length);

                return $result;
            },
            'BEN_ENDERECO_CIDADE' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_CIDADE']['field_id'];
                $length = $detahe2['BEN_ENDERECO_CIDADE']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Municipio'];

                $result = substr($result, 0, $length);

                return $result;
            },
            'BEN_ENDERECO_CEP' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_CEP']['field_id'];
                $length = $detahe2['BEN_ENDERECO_CEP']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_CEP'];

                $result = substr($result, 0, $length);

                return $result;
            },
            'BEN_ENDERECO_ESTADO' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_ESTADO']['field_id'];
                $length = $detahe2['BEN_ENDERECO_CIDADE']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Estado'];

                $result = substr($result, 0, $length);

                return $result;
            },
            'USO_BANCO_114' => '',
            'USO_BANCO_115' => '',
            'USO_BANCO_116' => '',
            'USO_BANCO_117' => '',
        ];

        $mappedTrailer1 = [
            'BANCO' => '',
            'LOTE' => '',
            'REGISTRO' => '',
            'USO_BANCO_126' => '',
            'QUANTIDADE_REGISTROS_127' => '',
            'VALOR_TOTAL_DOC_INTEIRO' => '',
            'VALOR_TOTAL_DOC_DECIMAL' => '',
            'USO_BANCO_130' => '',
            'USO_BANCO_131' => '',
            'USO_BANCO_132' => '',
        ];

        $mappedTrailer2 = [
            'BANCO' => '',
            'LOTE' => '',
            'REGISTRO' => '',
            'USO_BANCO_141' => '',
            'QUANTIDADE_LOTES-ARQUIVO' => '',
            'QUANTIDADE_REGISTROS_ARQUIVOS' => '',
            'USO_BANCO_144' => '',
            'USO_BANCO_145' => '',
        ];

        /**
         * Separa os registros em 3 categorias
         * $recordsBBPoupanca =  Contas polpança BB
         * $recordsBBCorrente = Contas corrente BB
         * $recordsOthers = Contas outros bancos
         */
        $recordsBBPoupanca = [];
        $recordsBBCorrente = [];
        $recordsOthers = [];
        $field_TipoConta = $default['field_TipoConta'];
        $field_banco = $default['field_banco'];
        $defaultBank = $default['defaultBank'];
        $correntistabb = $default['correntistabb'];
        $countMci460 = 0;

        if($default['ducumentsType']['mci460']){ // Caso exista separação entre bancarizados e desbancarizados

            if($defaultBank && $defaultBank ==  '001'){

                foreach ($registrations as $value) {

                    if ($value->$field_TipoConta == "Conta corrente" && $value->$correntistabb == "SIM") {
                        $recordsBBCorrente[] = $value;

                    } else if ($value->$field_TipoConta == "Conta poupança" && $value->$correntistabb == "SIM"){
                        $recordsBBPoupanca[] = $value;

                    }else{
                        $countMci460 ++;
                        $recordsOthers = [];
                        $app->log->info($value->number . " - Não incluída no CNAB240 pertence ao MCI460.");
                    }
                }

            }else{
                foreach ($registrations as $value) {
                    if ($this->numberBank($value->$field_banco) == "001" && $value->$correntistabb == "SIM") {
                        if ($value->$field_TipoConta == "Conta corrente") {
                            $recordsBBCorrente[] = $value;
                        } else {
                            $recordsBBPoupanca[] = $value;
                        }

                    } else {
                        $countMci460 ++;
                        $recordsOthers = [];
                        $app->log->info($value->number . "Não incluída no CNAB240 pertence ao MCI460.");
                    }
                }
            }
        }else{
            foreach ($registrations as $value) {
                if ($this->numberBank($value->$field_banco) == "001") {
                    if ($value->$field_TipoConta == "Conta corrente") {
                        $recordsBBCorrente[] = $value;
                    } else {
                        $recordsBBPoupanca[] = $value;
                    }

                } else {
                    $recordsOthers[] = $value;
                }
            }
        }

        //Mostra no terminal a quantidade de docs em cada documento MCI460, CNAB240
        if($default['ducumentsType']['mci460']){
            $app->log->info((count($recordsBBPoupanca) + count($recordsBBCorrente)) . " CNAB240");
            $app->log->info($countMci460 . " MCI460");
            sleep(5);
        }


        //Verifica se existe registros em algum dos arrays
        $validaExist = array_merge($recordsBBCorrente, $recordsOthers, $recordsBBPoupanca);
        if(empty($validaExist)){
            echo "Não foram encontrados registros analise os logs";
            exit();
        }



        /**
         * Monta o txt analisando as configs. caso tenha que buscar algo no banco de dados,
         * faz a pesquisa atravez do array mapped. Caso contrario busca o valor default da configuração
         *
         */

        $newline = "\r\n";

        $txt_data = "";
        $numLote = 0;
        $totaLotes = 0;
        $totalRegistros = 0;

        $complement = [];
        $txt_data = $this->mountTxt($header1, $mappedHeader1, $txt_data, null, null, $app);
        $totalRegistros += 1;

        $txt_data .= $newline;

        /**
         * Inicio banco do Brasil Corrente
         */
        $lotBBCorrente = 0;
        if ($recordsBBCorrente) {
            // Header 2
            $complement = [];
            $numLote++;
            $complement = [
                'FORMA_LANCAMENTO' => 01,
                'LOTE' => $numLote,
            ];

            $txt_data = $this->mountTxt($header2, $mappedHeader2, $txt_data, null, $complement, $app);
            $txt_data .= $newline;

            $lotBBCorrente += 1;

            $_SESSION['valor'] = 0;

            $totaLotes++;
            $numSeqRegistro = 0;

            //Detalhes 1 e 2

            foreach ($recordsBBCorrente as $key_records => $records) {
                $numSeqRegistro++;
                $complement = [
                    'LOTE' => $numLote,
                    'NUMERO_REGISTRO' => $numSeqRegistro,
                ];
                $txt_data = $this->mountTxt($detahe1, $mappedDeletalhe1, $txt_data, $records, $complement, $app);
                $txt_data .= $newline;

                $txt_data = $this->mountTxt($detahe2, $mappedDeletalhe2, $txt_data, $records, $complement, $app);
                $txt_data .= $newline;

                $lotBBCorrente += 2;

            }

            //treiller 1
            $lotBBCorrente += 1; // Adiciona 1 para obedecer a regra de somar o treiller 1
            $valor = explode(".", $_SESSION['valor']);
            $valor = preg_replace('/[^0-9]/i', '', $valor[0]);
            $complement = [
                'QUANTIDADE_REGISTROS_127' => $lotBBCorrente,
                'VALOR_TOTAL_DOC_INTEIRO' => $valor,

            ];

            $txt_data = $this->mountTxt($trailer1, $mappedTrailer1, $txt_data, null, $complement, $app);
            $txt_data .= $newline;
            $totalRegistros += $lotBBCorrente;
        }

        /**
         * Inicio banco do Brasil Poupança
         */
        $lotBBPoupanca = 0;
        if ($recordsBBPoupanca) {
            // Header 2
            $complement = [];
            $numLote++;
            $complement = [
                'FORMA_LANCAMENTO' => 05,
                'LOTE' => $numLote,
            ];
            $txt_data = $this->mountTxt($header2, $mappedHeader2, $txt_data, null, $complement, $app);
            $txt_data .= $newline;

            $lotBBPoupanca += 1;

            $_SESSION['valor'] = 0;

            $totaLotes++;
            $numSeqRegistro = 0;

            //Detalhes 1 e 2

            foreach ($recordsBBPoupanca as $key_records => $records) {
                $numSeqRegistro++;
                $complement = [
                    'LOTE' => $numLote,
                    'NUMERO_REGISTRO' => $numSeqRegistro,
                ];

                $txt_data = $this->mountTxt($detahe1, $mappedDeletalhe1, $txt_data, $records, $complement, $app);
                $txt_data .= $newline;

                $txt_data = $this->mountTxt($detahe2, $mappedDeletalhe2, $txt_data, $records, $complement, $app);
                $txt_data .= $newline;

                $lotBBPoupanca += 2;

            }

            //treiller 1
            $lotBBPoupanca += 1; // Adiciona 1 para obedecer a regra de somar o treiller 1
            $valor = explode(".", $_SESSION['valor']);
            $valor = preg_replace('/[^0-9]/i', '', $valor[0]);
            $complement = [
                'QUANTIDADE_REGISTROS_127' => $lotBBPoupanca,
                'VALOR_TOTAL_DOC_INTEIRO' => $valor,
                'LOTE' => $numLote,
            ];

            $txt_data = $this->mountTxt($trailer1, $mappedTrailer1, $txt_data, null, $complement, $app);
            $txt_data .= $newline;

            $totalRegistros += $lotBBPoupanca;
        }

        /**
         * Inicio Outros bancos
         */
        $lotOthers = 0;
        if ($recordsOthers) {
            //Header 2
            $complement = [];
            $numLote++;
            $complement = [
                'FORMA_LANCAMENTO' => 03,
                'LOTE' => $numLote,
            ];

            $txt_data = $this->mountTxt($header2, $mappedHeader2, $txt_data, null, $complement, $app);

            $txt_data .= "\r\n";

            $lotOthers += 1;

            $_SESSION['valor'] = 0;

            $totaLotes++;
            $numSeqRegistro = 0;

            //Detalhes 1 e 2

            foreach ($recordsOthers as $key_records => $records) {
                $numSeqRegistro++;
                $complement = [
                    'LOTE' => $numLote,
                    'NUMERO_REGISTRO' => $numSeqRegistro,
                ];
                $txt_data = $this->mountTxt($detahe1, $mappedDeletalhe1, $txt_data, $records, $complement, $app);

                $txt_data .= $newline;

                $txt_data = $this->mountTxt($detahe2, $mappedDeletalhe2, $txt_data, $records, $complement, $app);
                $txt_data .= $newline;
                $lotOthers += 2;

            }

            //treiller 1
            $lotOthers += 1; // Adiciona 1 para obedecer a regra de somar o treiller 1
            $valor = explode(".", $_SESSION['valor']);
            $valor = preg_replace('/[^0-9]/i', '', $valor[0]);
            $complement = [
                'QUANTIDADE_REGISTROS_127' => $lotOthers,
                'VALOR_TOTAL_DOC_INTEIRO' => $valor,
                'LOTE' => $numLote,
            ];
            $txt_data = $this->mountTxt($trailer1, $mappedTrailer1, $txt_data, null, $complement, $app);
            $txt_data .= $newline;
            $totalRegistros += $lotOthers;
        }

        //treiller do arquivo
        $totalRegistros += 1; // Adiciona 1 para obedecer a regra de somar o treiller
        $complement = [
            'QUANTIDADE_LOTES-ARQUIVO' => $totaLotes,
            'QUANTIDADE_REGISTROS_ARQUIVOS' => $totalRegistros,
        ];

        $txt_data = $this->mountTxt($trailer2, $mappedTrailer2, $txt_data, null, $complement, $app);

        if (isset($_SESSION['problems'])) {
            foreach ($_SESSION['problems'] as $key => $value) {
                $app->log->info("Problemas na inscrição " . $key . " => " . $value);
            }
            unset($_SESSION['problems']);
        }

        /**
         * cria o arquivo no servidor e insere o conteuto da váriavel $txt_data
         */
        $file_name = 'inciso1-cnab240- '.$opportunity_id.'-' . md5(json_encode($txt_data)) . '.txt';

        $dir = PRIVATE_FILES_PATH . 'aldirblanc/inciso1/remessas/cnab240/';

        $patch = $dir . $file_name;

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $stream = fopen($patch, 'w');

        fwrite($stream, $txt_data);

        fclose($stream);

        header('Content-Type: application/csv');
        header('Content-Disposition: attachment; filename=' . $file_name);
        header('Pragma: no-cache');
        readfile($patch);

    }

    /**
     * Implementa o exportador TXT no modelo CNAB 240, para envio de remessas ao banco do Brasil Inciso 2
     *
     *
     */
    public function ALL_exportCnab240Inciso2()
    {
        //Seta o timeout
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '768M');

        /**
         * Verifica se o usuário está autenticado
         */
        $this->requireAuthentication();
        $app = App::i();

        $getData = false;
        if (!empty($this->data)) {

            if (isset($this->data['from']) && isset($this->data['to'])) {

                if (!empty($this->data['from']) && !empty($this->data['to'])) {
                    if (!preg_match("/^[0-9]{4}-[0-9]{1,2}-[0-9]{1,2}$/", $this->data['from']) ||
                        !preg_match("/^[0-9]{4}-[0-9]{1,2}-[0-9]{1,2}$/", $this->data['to'])) {

                        throw new \Exception("O formato da data é inválido.");

                    } else {
                        //Data ínicial
                        $startDate = new DateTime($this->data['from']);
                        $startDate = $startDate->format('Y-m-d 00:00');

                        //Data final
                        $finishDate = new DateTime($this->data['to']);
                        $finishDate = $finishDate->format('Y-m-d 23:59');
                    }

                    $getData = true;
                }

            }

            //Pega a oportunidade do endpoint
            if (!isset($this->data['opportunity']) || empty($this->data['opportunity'])) {
                throw new Exception("Informe a oportunidade! Ex.: opportunity:2");

            } elseif (!is_numeric($this->data['opportunity']) || !in_array($this->data['opportunity'], $this->config['inciso2_opportunity_ids'])) {
                throw new Exception("Oportunidade inválida");

            } else {
                $opportunity_id = $this->data['opportunity'];
            }

        } else {
            throw new Exception("Informe a oportunidade! Ex.: opportunity:2");

        }

        /**
         * Pega informações da oportunidade
         */
        $opportunity = $app->repo('Opportunity')->find($opportunity_id);
        $this->registerRegistrationMetadata($opportunity);

        /**
         * Mapeamento de fielsds_id pelo label do campo
         */
        foreach ($opportunity->registrationFieldConfigurations as $field) {
            $field_labelMap["field_" . $field->id] = trim($field->title);

        }

        if (!$opportunity->canUser('@control')) {
            echo "Não autorizado";
            die();
        }

        /**
         * Pega os dados das configurações
         */
        $txt_config = $this->config['config-cnab240-inciso2'];
        $default = $txt_config['parameters_default'];
        $header1 = $txt_config['HEADER1'];
        $header2 = $txt_config['HEADER2'];
        $detahe1 = $txt_config['DETALHE1'];
        $detahe2 = $txt_config['DETALHE2'];
        $trailer1 = $txt_config['TRAILER1'];
        $trailer2 = $txt_config['TRAILER2'];

        foreach ($header1 as $key_config => $value) {
            if (is_string($value['field_id']) && strlen($value['field_id']) > 0 && $value['field_id'] != 'mapped') {
                $field_id = array_search(trim($value['field_id']), $field_labelMap);
                $header1[$key_config]['field_id'] = $field_id;
            }
        }

        foreach ($header2 as $key_config => $value) {
            if (is_string($value['field_id']) && strlen($value['field_id']) > 0 && $value['field_id'] != 'mapped') {
                $field_id = array_search(trim($value['field_id']), $field_labelMap);
                $header2[$key_config]['field_id'] = $field_id;
            }
        }

        foreach ($detahe1 as $key_config => $value) {
            if (is_string($value['field_id']) && strlen($value['field_id']) > 0 && $value['field_id'] != 'mapped') {
                $field_id = array_search(trim($value['field_id']), $field_labelMap);
                $detahe1[$key_config]['field_id'] = $field_id;
            }
        }

        foreach ($detahe2 as $key_config => $value) {
            if (is_string($value['field_id']) && strlen($value['field_id']) > 0 && $value['field_id'] != 'mapped') {
                $field_id = array_search(trim($value['field_id']), $field_labelMap);
                $detahe2[$key_config]['field_id'] = $field_id;
            }
        }

        foreach ($trailer1 as $key_config => $value) {
            if (is_string($value['field_id']) && strlen($value['field_id']) > 0 && $value['field_id'] != 'mapped') {
                $field_id = array_search(trim($value['field_id']), $field_labelMap);
                $trailer1[$key_config]['field_id'] = $field_id;
            }
        }

        foreach ($trailer2 as $key_config => $value) {
            if (is_string($value['field_id']) && strlen($value['field_id']) > 0 && $value['field_id'] != 'mapped') {
                $field_id = array_search(trim($value['field_id']), $field_labelMap);
                $trailer2[$key_config]['field_id'] = $field_id;
            }
        }

        /**
         * Busca as inscrições com status 10 (Selecionada)
         */
        if ($getData) {
            $dql = "SELECT e FROM MapasCulturais\Entities\Registration e
            WHERE e.status = 1 AND
            e.opportunity = :opportunity_Id AND
            e.sentTimestamp >=:startDate AND
            e.sentTimestamp <= :finishDate";

            $query = $app->em->createQuery($dql);
            $query->setParameters([
                'opportunity_Id' => $opportunity_id,
                'startDate' => $startDate,
                'finishDate' => $finishDate,
            ]);

            $registrations = $query->getResult();

        } else {
            $dql = "SELECT e FROM MapasCulturais\Entities\Registration e
            WHERE e.status = 1 AND
            e.opportunity = :opportunity_Id";

            $query = $app->em->createQuery($dql);
            $query->setParameters([
                'opportunity_Id' => $opportunity_id,
            ]);

            $registrations = $query->getResult();

        }

        if (empty($registrations)) {
            echo "Não foram encontrados registros.";
            die();
        }

        $mappedHeader1 = [
            'BANCO' => '',
            'LOTE' => '',
            'REGISTRO' => '',
            'USO_BANCO_12' => '',
            'INSCRICAO_TIPO' => '',
            'CPF_CNPJ_FONTE_PAG' => '',
            'CONVENIO_BB1' => '',
            'CONVENIO_BB2' => '',
            'CONVENIO_BB3' => '',
            'CONVENIO_BB4' => '',
            'AGENCIA' => function ($registrations) use ($header1) {
                $result = "";
                $field_id = $header1['AGENCIA'];
                $value = $this->normalizeString($field_id['default']);
                return substr($value, 0, 4);

            },
            'AGENCIA_DIGITO' => function ($registrations) use ($header1) {
                $result = "";
                $field_id = $header1['AGENCIA_DIGITO'];
                $value = $this->normalizeString($field_id['default']);
                $result = is_string($value) ? strtoupper($value) : $value;
                return $result;

            },
            'CONTA' => function ($registrations) use ($header1) {
                $result = "";
                $field_id = $header1['CONTA'];
                $value = $this->normalizeString($field_id['default']);
                return substr($value, 0, 12);
            },
            'CONTA_DIGITO' => function ($registrations) use ($header1) {
                $result = "";
                $field_id = $header1['CONTA_DIGITO'];
                $value = $this->normalizeString($field_id['default']);
                $result = is_string($value) ? strtoupper($value) : $value;
                return $result;

            },
            'USO_BANCO_20' => '',
            'NOME_EMPRESA' => function ($registrations) use ($header1, $app) {
                $result =  $header1['NOME_EMPRESA']['default'];
                return substr($result, 0, 30);
            },
            'NOME_BANCO' => '',
            'USO_BANCO_23' => '',
            'CODIGO_REMESSA' => '',
            'DATA_GER_ARQUIVO' => function ($registrations) use ($detahe1) {
                $date = new DateTime();
                return $date->format('dmY');
            },
            'HORA_GER_ARQUIVO' => function ($registrations) use ($detahe1) {
                $date = new DateTime();
                return $date->format('His');
            },
            'NUM_SERQUNCIAL_ARQUIVO' => '',
            'LAYOUT_ARQUIVO' => '',
            'DENCIDADE_GER_ARQUIVO' => '',
            'USO_BANCO_30' => '',
            'USO_BANCO_31' => '',
        ];

        $mappedHeader2 = [
            'BANCO' => '',
            'LOTE' => '',
            'REGISTRO' => '',
            'OPERACAO' => '',
            'SERVICO' => '',
            'FORMA_LANCAMENTO' => '',
            'LAYOUT_LOTE' => '',
            'USO_BANCO_43' => '',
            'INSCRICAO_TIPO' => '',
            'INSCRICAO_NUMERO' => '',
            'CONVENIO_BB1' => '',
            'CONVENIO_BB2' => '',
            'CONVENIO_BB3' => '',
            'CONVENIO_BB4' => '',
            'AGENCIA' => function ($registrations) use ($header2) {
                $result = "";
                $field_id = $header2['AGENCIA'];
                $value = $this->normalizeString($field_id['default']);
                return substr($value, 0, 4);

            },
            'AGENCIA_DIGITO' => function ($registrations) use ($header2) {
                $result = "";
                $field_id = $header2['AGENCIA_DIGITO'];
                $value = $this->normalizeString($field_id['default']);
                $result = is_string($value) ? strtoupper($value) : $value;
                return $result;

            },
            'CONTA' => function ($registrations) use ($header2) {
                $result = "";
                $field_id = $header2['CONTA'];
                $value = $this->normalizeString($field_id['default']);
                return substr($value, 0, 12);


            },
            'CONTA_DIGITO' => function ($registrations) use ($header2) {
                $result = "";
                $field_id = $header2['CONTA_DIGITO'];
                $value = $this->normalizeString($field_id['default']);
                $result = is_string($value) ? strtoupper($value) : $value;
                return $result;

            },
            'USO_BANCO_51' => '',
            'NOME_EMPRESA' => function ($registrations) use ($header2, $app) {
                $result =  $header2['NOME_EMPRESA']['default'];
                return substr($result, 0, 30);
            },
            'USO_BANCO_40' => '',
            'LOGRADOURO' => '',
            'NUMERO' => '',
            'COMPLEMENTO' => '',
            'CIDADE' => '',
            'CEP' => '',
            'ESTADO' => '',
            'USO_BANCO_60' => '',
            'USO_BANCO_61' => '',
        ];

        $mappedDeletalhe1 = [
            'BANCO' => '',
            'LOTE' => '',
            'REGISTRO' => '',
            'NUMERO_REGISTRO' => '',
            'SEGMENTO' => '',
            'TIPO_MOVIMENTO' => '',
            'CODIGO_MOVIMENTO' => '',
            'CAMARA_CENTRALIZADORA' => '',
            'BEN_CODIGO_BANCO' => function ($registrations) use ($detahe1) {
                $field_id = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                return $this->numberBank($registrations->$field_id);

            },
            'BEN_AGENCIA' => function ($registrations) use ($detahe1, $default) {

                $temp = $default['formoReceipt'];
                $formoReceipt = $registrations->$temp;

                if($formoReceipt == "CARTEIRA DIGITAL BB"){
                    $field_id = $default['fieldsWalletDigital']['agency'];
                }else{
                    $field_id = $detahe1['BEN_AGENCIA']['field_id'];
                }

                $age = $this->normalizeString($registrations->$field_id);

                if (strlen($age) > 4) {

                    $result = substr($age, 0, 4);
                } else {
                    $result = $age;
                }

                return is_string($result) ? strtoupper($result) : $result;
            },
            'BEN_AGENCIA_DIGITO' => function ($registrations) use ($detahe1, $default) {
                $result = "";

                $temp = $default['formoReceipt'];
                $formoReceipt = $registrations->$temp;

                if($formoReceipt == "CARTEIRA DIGITAL BB"){
                    $field_id = $default['fieldsWalletDigital']['agency'];
                }else{
                    $field_id = $detahe1['BEN_AGENCIA_DIGITO']['field_id'];
                }

                $dig = $this->normalizeString($registrations->$field_id);
                if (strlen($dig) > 4) {
                    $result = substr($dig, -1);
                } else {
                    $result = "";
                }

                return is_string($result) ? strtoupper($result) : $result;
            },
            'BEN_CONTA' => function ($registrations) use ($detahe1, $default, $app) {
                $temp = $default['formoReceipt'];
                $formoReceipt = $registrations->$temp;

                if($formoReceipt == "CARTEIRA DIGITAL BB"){
                    $field_id = $default['fieldsWalletDigital']['account'];
                }else{
                    $field_id = $detahe1['BEN_CONTA_DIGITO']['field_id'];
                }

                $account = $this->normalizeString($registrations->$field_id);

                $temp = $detahe1['TIPO_CONTA']['field_id'];
                $typeAccount = $registrations->$temp;

                $temp = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                if($temp){
                    $numberBank = $this->numberBank($registrations->$temp);
                }else{
                    $numberBank = $default['defaultBank'];
                }

                if(!$account){
                    $app->log->info($registrations->number . " Conta bancária não informada");
                    return " ";
                }

                $result  = "";
                if($typeAccount == $default['typesAccount']['poupanca']){

                    if (($numberBank == '001') && (substr($account, 0, 3) != "510")) {

                        $result = "510" . $account;

                    }else{
                        $result = $account;
                    }
                }else{
                    $result = $account;
                }

                $result = preg_replace('/[^0-9]/i', '',$result);

                if($temp === $field_id){
                    return substr($this->normalizeString($result), 0, -1); // Remove o ultimo caracter. Intende -se que o ultimo caracter é o DV da conta

                }else{
                    return $result;

                }




            },
            'BEN_CONTA_DIGITO' => function ($registrations) use ($detahe1, $default, $app) {
                $temp = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                $field_id = $detahe1['BEN_CONTA']['field_id'];

                if($temp){
                    $numberBank = $this->numberBank($registrations->$temp);
                }else{
                    $numberBank = $default['defaultBank'];
                }

                $temp = $default['formoReceipt'];
                $formoReceipt = $registrations->$temp;

                if($formoReceipt == "CARTEIRA DIGITAL BB"){
                    $temp = $default['fieldsWalletDigital']['account'];
                }else{
                    $temp = $detahe1['BEN_CONTA_DIGITO']['field_id'];
                }

                $account = $this->normalizeString(preg_replace('/[^0-9]/i', '', $registrations->$temp));

                $temp = $detahe1['TIPO_CONTA']['field_id'];
                $typeAccount = $registrations->$temp;

                $dig = substr($account, -1);

                if(!$account && ($temp == $field_id)){
                    $app->log->info($registrations->number . " Conta sem DV");
                    return " ";
                }

                $result = "";

                if ($numberBank == '001' && $typeAccount == $default['typesAccount']['poupanca']) {
                    if (substr($account, 0, 3) == "510") {
                        $result = $dig;
                    } else {

                        $result = $default['savingsDigit'][$dig];

                    }
                } else {

                    $result = $dig;
                }

                return is_string($result) ? strtoupper($result) : $result;

            },
            'BEN_DIGITO_CONTA_AGENCIA_80' => '',
            'BEN_NOME' => function ($registrations) use ($detahe1) {
                $field_id = $detahe1['BEN_NOME']['field_id'];
                $result = substr($this->normalizeString($registrations->$field_id), 0, $detahe1['BEN_NOME']['length']);
                return $result;
            },
            'BEN_DOC_ATRIB_EMPRESA_82' => '',
            'DATA_PAGAMENTO' => function ($registrations) use ($detahe1) {
                $date = new DateTime();
                $date->add(new DateInterval('P1D'));
                $weekday = $date->format('D');

                $weekdayList = [
                    'Mon' => true,
                    'Tue' => true,
                    'Wed' => true,
                    'Thu' => true,
                    'Fri' => true,
                    'Sat' => false,
                    'Sun' => false,
                ];

                while (!$weekdayList[$weekday]) {
                    $date->add(new DateInterval('P1D'));
                    $weekday = $date->format('D');
                }

                return $date->format('dmY');
            },
            'TIPO_MOEDA' => '',
            'USO_BANCO_85' => '',
            'VALOR_INTEIRO' => function ($registrations) use ($detahe1, $default) {
                $field_id = $default['womanMonoParent'];
                if($registrations->$field_id == "SIM"){
                    $valor = '6000.00';
                }else{
                    $valor = '3000.00';
                }
                $valor = preg_replace('/[^0-9]/i', '', $valor);
                return $valor;
            },
            'USO_BANCO_88' => '',
            'USO_BANCO_89' => '',
            'USO_BANCO_90' => '',
            'CODIGO_FINALIDADE_TED' => function ($registrations) use ($detahe1, $default) {
                $temp = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                if($temp){
                    $numberBank = $this->numberBank($registrations->$temp);
                }else{
                    $numberBank = $default['defaultBank'];
                }
                if ($numberBank != "001") {
                    return '10';
                } else {
                    return "";
                }
            },
            'USO_BANCO_92' => '',
            'USO_BANCO_93' => '',
            'TIPO_CONTA' => '',
        ];

        $mappedDeletalhe2 = [
            'BANCO' => '',
            'LOTE' => '',
            'REGISTRO' => '',
            'NUMERO_REGISTRO' => '',
            'SEGMENTO' => '',
            'USO_BANCO_104' => '',
            'BEN_TIPO_DOC' => '',
            'BEN_CPF' => function ($registrations) use ($detahe2) {
                $field_id = $detahe2['BEN_CPF']['field_id'];
                $data = $registrations->$field_id;
                if (strlen($this->normalizeString($data)) != 11) {
                    $_SESSION['problems'][$registrations->number] = "CPF Inválido";
                }
                return $data;
            },
            'BEN_ENDERECO_LOGRADOURO' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_LOGRADOURO']['field_id'];
                $length = $detahe2['BEN_ENDERECO_LOGRADOURO']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Nome_Logradouro'];

                $result = substr($result, 0, $length);

                return $result;

            },
            'BEN_ENDERECO_NUMERO' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_NUMERO']['field_id'];
                $length = $detahe2['BEN_ENDERECO_NUMERO']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Num'];

                $result = substr($result, 0, $length);

                return $result;
            },
            'BEN_ENDERECO_COMPLEMENTO' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_COMPLEMENTO']['field_id'];
                $length = $detahe2['BEN_ENDERECO_COMPLEMENTO']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Complemento'];

                $result = substr($result, 0, $length);

                return $result;
            },
            'BEN_ENDERECO_BAIRRO' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_BAIRRO']['field_id'];
                $length = $detahe2['BEN_ENDERECO_BAIRRO']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Bairro'];

                $result = substr($result, 0, $length);

                return $result;
            },
            'BEN_ENDERECO_CIDADE' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_CIDADE']['field_id'];
                $length = $detahe2['BEN_ENDERECO_CIDADE']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Municipio'];

                $result = substr($result, 0, $length);

                return $result;
            },
            'BEN_ENDERECO_CEP' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_CEP']['field_id'];
                $length = $detahe2['BEN_ENDERECO_CEP']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_CEP'];

                $result = substr($result, 0, $length);

                return $result;
            },
            'BEN_ENDERECO_ESTADO' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_ESTADO']['field_id'];
                $length = $detahe2['BEN_ENDERECO_CIDADE']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Estado'];

                $result = substr($result, 0, $length);

                return $result;
            },
            'USO_BANCO_114' => '',
            'USO_BANCO_115' => '',
            'USO_BANCO_116' => '',
            'USO_BANCO_117' => '',
        ];

        $mappedTrailer1 = [
            'BANCO' => '',
            'LOTE' => '',
            'REGISTRO' => '',
            'USO_BANCO_126' => '',
            'QUANTIDADE_REGISTROS_127' => '',
            'VALOR_TOTAL_DOC_INTEIRO' => '',
            'VALOR_TOTAL_DOC_DECIMAL' => '',
            'USO_BANCO_130' => '',
            'USO_BANCO_131' => '',
            'USO_BANCO_132' => '',
        ];

        $mappedTrailer2 = [
            'BANCO' => '',
            'LOTE' => '',
            'REGISTRO' => '',
            'USO_BANCO_141' => '',
            'QUANTIDADE_LOTES-ARQUIVO' => '',
            'QUANTIDADE_REGISTROS_ARQUIVOS' => '',
            'USO_BANCO_144' => '',
            'USO_BANCO_145' => '',
        ];

        /**
         * Separa os registros em 3 categorias
         * $recordsBBPoupanca =  Contas polpança BB
         * $recordsBBCorrente = Contas corrente BB
         * $recordsOthers = Contas outros bancos
         */
        $recordsBBPoupanca = [];
        $recordsBBCorrente = [];
        $recordsOthers = [];
        $field_TipoConta = array_search(trim($default['field_TipoConta']), $field_labelMap);
        $field_banco = array_search(trim($default['field_banco']), $field_labelMap);
        $defaultBank = $default['defaultBank'];
        $correntistabb = $default['correntistabb'];
        $countMci460 = 0;

        if($default['ducumentsType']['mci460']){ // Caso exista separação entre bancarizados e desbancarizados

            if($defaultBank && $defaultBank ==  '001'){

                foreach ($registrations as $value) {

                    if ($value->$field_TipoConta == "Conta corrente" && $value->$correntistabb == "SIM") {
                        $recordsBBCorrente[] = $value;

                    } else if ($value->$field_TipoConta == "Conta poupança" && $value->$correntistabb == "SIM"){
                        $recordsBBPoupanca[] = $value;

                    }else{
                        $countMci460 ++;
                        $recordsOthers = [];
                        $app->log->info($value->number . " - Não incluída no CNAB240 pertence ao MCI460.");
                    }
                }

            }else{
                foreach ($registrations as $value) {
                    if ($this->numberBank($value->$field_banco) == "001" && $value->$correntistabb == "SIM") {
                        if ($value->$field_TipoConta == "Conta corrente") {
                            $recordsBBCorrente[] = $value;
                        } else {
                            $recordsBBPoupanca[] = $value;
                        }

                    } else {
                        $countMci460 ++;
                        $recordsOthers = [];
                        $app->log->info($value->number . "Não incluída no CNAB240 pertence ao MCI460.");
                    }
                }
            }
        }else{
            foreach ($registrations as $value) {
                if ($this->numberBank($value->$field_banco) == "001") {
                    if ($value->$field_TipoConta == "Conta corrente") {
                        $recordsBBCorrente[] = $value;
                    } else {
                        $recordsBBPoupanca[] = $value;
                    }

                } else {
                    $recordsOthers[] = $value;
                }
            }
        }

        //Mostra no terminal a quantidade de docs em cada documento MCI460, CNAB240
        if($default['ducumentsType']['mci460']){
            $app->log->info((count($recordsBBPoupanca) + count($recordsBBCorrente)) . " CNAB240");
            $app->log->info($countMci460 . " MCI460");
            sleep(5);
        }

         //Verifica se existe registros em algum dos arrays
         $validaExist = array_merge($recordsBBCorrente, $recordsOthers, $recordsBBPoupanca);
         if(empty($validaExist)){
             echo "Não foram encontrados registros analise os logs";
             exit();
         }


        /**
         * Monta o txt analisando as configs. caso tenha que buscar algo no banco de dados,
         * faz a pesquisa atravez do array mapped. Caso contrario busca o valor default da configuração
         *
         */
        $newline = "\r\n";

        $txt_data = "";
        $numLote = 0;
        $totaLotes = 0;
        $totalRegistros = 0;

        $complement = [];
        $txt_data = $this->mountTxt($header1, $mappedHeader1, $txt_data, null, null, $app);
        $totalRegistros += 1;

        $txt_data .= $newline;

        /**
         * Inicio banco do Brasil Corrente
         */
        $lotBBCorrente = 0;
        if ($recordsBBCorrente) {
            // Header 2
            $complement = [];
            $numLote++;
            $complement = [
                'FORMA_LANCAMENTO' => 01,
                'LOTE' => $numLote,
            ];

            $txt_data = $this->mountTxt($header2, $mappedHeader2, $txt_data, null, $complement, $app);
            $txt_data .= $newline;

            $lotBBCorrente += 1;

            $_SESSION['valor'] = 0;

            $totaLotes++;
            $numSeqRegistro = 0;

            //Detalhes 1 e 2

            foreach ($recordsBBCorrente as $key_records => $records) {
                $numSeqRegistro++;
                $complement = [
                    'LOTE' => $numLote,
                    'NUMERO_REGISTRO' => $numSeqRegistro,
                ];
                $txt_data = $this->mountTxt($detahe1, $mappedDeletalhe1, $txt_data, $records, $complement, $app);
                $txt_data .= $newline;

                $txt_data = $this->mountTxt($detahe2, $mappedDeletalhe2, $txt_data, $records, $complement, $app);
                $txt_data .= $newline;

                $lotBBCorrente += 2;

            }

            //treiller 1
            $lotBBCorrente + 1; // Adiciona 1 para obedecer a regra de somar o treiller 1
            $valor = explode(".", $_SESSION['valor']);
            $valor = preg_replace('/[^0-9]/i', '', $valor[0]);
            $complement = [
                'QUANTIDADE_REGISTROS_127' => $lotBBCorrente,
                'VALOR_TOTAL_DOC_INTEIRO' => $valor,

            ];

            $txt_data = $this->mountTxt($trailer1, $mappedTrailer1, $txt_data, null, $complement, $app);
            $txt_data .= $newline;
            $totalRegistros += $lotBBCorrente;
        }

        /**
         * Inicio banco do Brasil Poupança
         */
        $lotBBPoupanca = 0;
        if ($recordsBBPoupanca) {
            // Header 2
            $complement = [];
            $numLote++;
            $complement = [
                'FORMA_LANCAMENTO' => 5,
                'LOTE' => $numLote,
            ];
            $txt_data = $this->mountTxt($header2, $mappedHeader2, $txt_data, null, $complement, $app);
            $txt_data .= $newline;

            $lotBBPoupanca += 1;

            $_SESSION['valor'] = 0;

            $totaLotes++;
            $numSeqRegistro = 0;

            //Detalhes 1 e 2

            foreach ($recordsBBPoupanca as $key_records => $records) {
                $numSeqRegistro++;
                $complement = [
                    'LOTE' => $numLote,
                    'NUMERO_REGISTRO' => $numSeqRegistro,
                ];

                $txt_data = $this->mountTxt($detahe1, $mappedDeletalhe1, $txt_data, $records, $complement, $app);
                $txt_data .= $newline;

                $txt_data = $this->mountTxt($detahe2, $mappedDeletalhe2, $txt_data, $records, $complement, $app);
                $txt_data .= $newline;

                $lotBBPoupanca += 2;

            }

            //treiller 1
            $lotBBPoupanca += 1; // Adiciona 1 para obedecer a regra de somar o treiller 1
            $valor = explode(".", $_SESSION['valor']);
            $valor = preg_replace('/[^0-9]/i', '', $valor[0]);
            $complement = [
                'QUANTIDADE_REGISTROS_127' => $lotBBPoupanca,
                'VALOR_TOTAL_DOC_INTEIRO' => $valor,
                'LOTE' => $numLote,
            ];

            $txt_data = $this->mountTxt($trailer1, $mappedTrailer1, $txt_data, null, $complement, $app);
            $txt_data .= $newline;

            $totalRegistros += $lotBBPoupanca;
        }

        /**
         * Inicio Outros bancos
         */
        $lotOthers = 0;
        if ($recordsOthers) {
            //Header 2
            $complement = [];
            $numLote++;
            $complement = [
                'FORMA_LANCAMENTO' => 03,
                'LOTE' => $numLote,
            ];

            $txt_data = $this->mountTxt($header2, $mappedHeader2, $txt_data, null, $complement, $app);

            $txt_data .= $newline;

            $lotOthers += 1;

            $_SESSION['valor'] = 0;

            $totaLotes++;
            $numSeqRegistro = 0;

            //Detalhes 1 e 2

            foreach ($recordsOthers as $key_records => $records) {
                $numSeqRegistro++;
                $complement = [
                    'LOTE' => $numLote,
                    'NUMERO_REGISTRO' => $numSeqRegistro,
                ];
                $txt_data = $this->mountTxt($detahe1, $mappedDeletalhe1, $txt_data, $records, $complement, $app);

                $txt_data .= $newline;

                $txt_data = $this->mountTxt($detahe2, $mappedDeletalhe2, $txt_data, $records, $complement, $app);
                $txt_data .= $newline;
                $lotOthers += 2;

            }

            //treiller 1
            $lotOthers += 1; // Adiciona 1 para obedecer a regra de somar o treiller 1
            $valor = explode(".", $_SESSION['valor']);
            $valor = preg_replace('/[^0-9]/i', '', $valor[0]);
            $complement = [
                'QUANTIDADE_REGISTROS_127' => $lotOthers,
                'VALOR_TOTAL_DOC_INTEIRO' => $valor,
                'LOTE' => $numLote,
            ];
            $txt_data = $this->mountTxt($trailer1, $mappedTrailer1, $txt_data, null, $complement, $app);
            $txt_data .= $newline;
            $totalRegistros += $lotOthers;
        }

        //treiller do arquivo
        $totalRegistros += 1; // Adiciona 1 para obedecer a regra de somar o treiller
        $complement = [
            'QUANTIDADE_LOTES-ARQUIVO' => $totaLotes,
            'QUANTIDADE_REGISTROS_ARQUIVOS' => $totalRegistros,
        ];

        $txt_data = $this->mountTxt($trailer2, $mappedTrailer2, $txt_data, null, $complement, $app);

        if (isset($_SESSION['problems'])) {
            foreach ($_SESSION['problems'] as $key => $value) {
                $app->log->info("Problemas na inscrição " . $key . " => " . $value);
            }
            unset($_SESSION['problems']);
        }

        /**
         * cria o arquivo no servidor e insere o conteuto da váriavel $txt_data
         */
        $file_name = 'inciso2-cnab240-'.$opportunity_id.'-' . md5(json_encode($txt_data)) . '.txt';

        $dir = PRIVATE_FILES_PATH . 'aldirblanc/inciso1/remessas/cnab240/';

        $patch = $dir . $file_name;

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $stream = fopen($patch, 'w');

        fwrite($stream, $txt_data);

        fclose($stream);

        header('Content-Type: application/csv');
        header('Content-Disposition: attachment; filename=' . $file_name);
        header('Pragma: no-cache');
        readfile($patch);

    }

    public function ALL_exportBankless()
    {
        $this->requireAuthentication();
        $app = App::i();
        $parameters = $this->getURLParameters([
            "opportunity" => "intArray",
            "from" => "date",
            "to" => "date",
            "type" => "string"
        ]);
        $startDate = null;
        $finishDate = null;
        if (isset($parameters["from"])) {
            if (!isset($parameters["to"])) {
                throw new Exception("Ao informar filtro de data, os dois limites devem ser informados.");
            }
            $startDate = $parameters["from"];
            $finishDate = $parameters["to"];
        }
        // pega oportunidades via ORM
        $opportunities = [];
        if (isset($parameters["opportunity"])) {
            $opportunities = $app->repo("Opportunity")->findBy(["id" => $parameters["opportunity"]]);
        } else {
            $opportunities = $app->repo("Opportunity")->findAll();
        }
        foreach ($opportunities as $opportunity) {
            if (!$opportunity->canUser('@control')) {
                echo "Não autorizado.";
                die();
            }
        }
        if (!isset($parameters["type"])) {
            $parameters["type"] = "mci460";
        }
        switch ($parameters["type"]) {
            case "mci460":
                $this->exportMCI460($opportunities, $startDate, $finishDate);
                break;
            case "addressReport":
                $this->addressReport($opportunities, $startDate, $finishDate);
                break;
            default:
                throw new Exception("Arquivo desconhecido: " . $parameters["type"]);
        }
        return;
    }

    /**
     * Obtém o relatório de formatos de dados bancários em JSON.
     * Parâmetros da URL:
     * - op=n[,...] - oportunidades a consultar (padrão: todas)
     * - status=n[,...] - status a consultar (padrão: selecionadas e pendentes)
     * - strict - exige que os números de agência e conta tenham tamanho exato,
     *            não tenta obter a operação do número da conta
     * Esta é uma chamada potencialmente lenta dependendo do banco de dados e
     * parâmetros passados na URL.
     */
    public function ALL_bankingDataReport()
    {
        $this->requireAuthentication();
        $app = App::i();
        // processa parâmetros da URL
        if (!empty($this->data)) {
            // oportunidades
            if (isset($this->data["op"])) {
                $opportunityIDs = explode(",", $this->data["op"]);
                foreach ($opportunityIDs as $oID) {
                    if (!is_numeric($oID)) {
                        throw new Exception("Oportunidade(s) inválida(s)");
                    }
                }
            }
            // statuses
            if (isset($this->data["status"])) {
                $statuses = explode(",", $this->data["status"]);
                foreach ($statuses as $st) {
                    if (!is_numeric($st)) {
                        throw new Exception("Status(es) inválido(s)");
                    }
                }
            }
        }
        // pega oportunidades via ORM
        $opportunities = [];
        if (isset($opportunityIDs)) {
            $opportunities = $app->repo("Opportunity")->findBy(["id" => $opportunityIDs]);
        } else {
            $opportunities = $app->repo("Opportunity")->findAll();
        }
        $statusList = isset($statuses) ? $statuses : [
            Registration::STATUS_APPROVED,
            Registration::STATUS_SENT,
        ];
        // $reportOut = "";
        $report["noValidationRules"] = [];
        $report["missingBasicData"] = [];
        $report["invalid"] = [];
        $report["valid"] = [];
        $banklessPayment = 0;
        $banksMissingRules = [];
        $missingBanks = [];
        $totalRecords = 0;
        set_time_limit(0);
        //header("Content-type: text/utf-8");
        header("Content-type: application/json");
        flush();
        foreach ($opportunities as $opportunity) {
            // pega inscrições via DQL seguindo recomendações do Doctrine para grandes volumes
            $dql = "SELECT e FROM MapasCulturais\Entities\Registration e
                             WHERE e.status IN (:statusList) AND
                                   e.opportunity=:oppId";
            $query = $app->em->createQuery($dql);
            $registrations = $query->iterate(["oppId" => $opportunity->id,
                                              "statusList" => $statusList]);
            /**
             * Mapeamento de fielsds_id pelo label do campo
             */
            $this->registerRegistrationMetadata($opportunity);
            $interestFields = $this->findFields([
                "bank", "branch", "account", "operation", "payment"
            ], $opportunity->registrationFieldConfigurations);
            $regsOpp[$opportunity->id] = 0;
            // processa inscrições
            while ($registration = $registrations->next()[0]) {
                ++$totalRecords;
                // contabiliza e pula ordem de pagamento
                if (isset($interestFields["payment"])) {
                    $payment = $interestFields["payment"];
                    $payment = $registration->$payment;
                    if (strstr(strtolower($payment), "ordem de pagamento")) {
                        ++$banklessPayment;
                        continue;
                    }
                }
                // pega dados dos campos de interesse
                if (isset($interestFields["bank"])) {
                    $bank = $interestFields["bank"];
                    $bank = $registration->$bank;
                } else {
                    $bank = $this->config["config-bankdata"]["fields"]["defaults"]["bank"];
                }
                $bankNumber = $this->numberBank($bank);
                $branch = $interestFields["branch"];
                $branch = $registration->$branch;
                $account = $interestFields["account"];
                $account = $registration->$account;
                if (isset($interestFields["operation"])) {
                    $operation = $interestFields["operation"];
                    $operation = $registration->$operation;
                } else {
                    $operation = $this->config["config-bankdata"]["fields"]["defaults"]["operation"];
                }
                // registra bancos sem código no config-bankdata.php
                if ((strlen($bank) != 0) && (strlen($bankNumber) == 0)) {
                    $missingBanks[$bank] = true;
                }
                // inclui no relatório qualquer inscrição que informe pelo menos um dos campos
                if ($bank || $branch || $account || $operation) {
                    $reportLine = "banco[$bankNumber] agência[$branch] " .
                                  "conta[$account] operação[$operation]";
                    $valid = $this->verifyBankingInfo($bankNumber, $branch,
                                                      $account, $operation);
                    // inscrições com dados faltando
                    if ($valid == Remessas::VALIDATION_MISSING_DATA) {
                        $report["missingBasicData"][] = [
                            "id" => $registration->id,
                            "opportunity" => $opportunity->id,
                            "report" => $reportLine
                        ];
                    // inscrições que faltam regras de validação no config-bankdata.php
                    } else if ($valid == Remessas::VALIDATION_NORULE) {
                        $report["noValidationRules"][] = [
                            "id" => $registration->id,
                            "opportunity" => $opportunity->id,
                            "report" => $reportLine
                        ];
                        $banksMissingRules["$bankNumber"] = true;
                    // inscrições que não passaram na validação
                    } else if ($valid != Remessas::VALIDATION_PASSED) {
                        $report["invalid"][] = [
                            "id" => $registration->id,
                            "opportunity" => $opportunity->id,
                            "flags" => $valid,
                            "report" => $reportLine
                        ];
                    // inscrições válidas formatadas
                    } else {
                        $formatted = $this->formattedBankingInfo($bankNumber, $branch,
                                                                 $account, $operation);
                        $branch = $formatted["branch"]["formatted"];
                        $fAccount = $formatted["account"]["formatted"];
                        $operation = isset($formatted["operation"]) ? $formatted["operation"] : "";
                        $report["valid"][] = "banco[$bankNumber] agência[$branch] " .
                                             "conta[$fAccount|$account] operação[$operation]";
                    }
                }
                $app->em->clear();
            }
        }
        // finaliza a preparação do JSON
        if (!empty($missingBanks)) {
            $report["missingBanks"] = array_keys($missingBanks);
        }
        if (!empty($banksMissingRules)) {
            $report["banksMissingRules"] = array_keys($banksMissingRules);
        }
        $report["counts"] = [
            "processed" => $totalRecords,
            "noValidationRules" => sizeof($report["noValidationRules"]),
            "missingBasicData" => sizeof($report["missingBasicData"]),
            "invalid" => sizeof($report["invalid"]),
            "valid" => sizeof($report["valid"]),
            "bankless" => $banklessPayment
        ];
        echo(json_encode($report));
        return;
    }

    //###################################################################################################################################

    /**
     * Placeholder para o número de seqüência dos arquivos de remessa.
     */
    private function sequenceNumber($type)
    {
        $n = 0;
        switch ($type) {
            case "cnab240": break;
            case "mci460": break;
            default: break;
        }
        return $n;
    }

    /**
     * Pega a string e enquadra a mesma no formato necessario para tender o modelo CNAB 240
     * Caso a string nao atenda o numero de caracteres desejado, ela completa com zero ou espaço em banco
     */
    private function createString($value)
    {
        $data = "";
        $length = $value['length'];
        $type = $value['type'];
        $value['default'] = Normalizer::normalize($value['default'], Normalizer::FORM_D);
        $regex = isset($value['filter']) ? $value['filter'] : '/[^a-z0-9 ]/i';
        $value['default'] = preg_replace($regex, '', $value['default']);
        if ($type === 'int') {
            $data .= str_pad($value['default'], $length, '0', STR_PAD_LEFT);
        } else {
            $data .= str_pad($value['default'], $length, " ");
        }

        return substr($data, 0, $length);
    }

    /**
     * Encontra os campos solicitados de acordo com o config-bankdata.
     * Retornará um dicionário com entradas como ["<name>" => "<field_id>"].
     */
    private function findFields($fieldNames, $fieldConfigs)
    {
        $fields = [];
        $bankConfig = $this->config["config-bankdata"]["fields"];
        foreach ($fieldConfigs as $field) {
            $title = trim($field->title);
            foreach ($fieldNames as $name) {
                if (isset($bankConfig["names"][$name]) &&
                    in_array($title, $bankConfig["names"][$name])) {
                    $fields[$name] = "field_" . $field->id;
                    break;
                }
            }
            if (sizeof($fields) > sizeof($fieldNames)) {
                break;
            }
        }
        return $fields;
    }

    /**
     * Retorna as informações bancárias formatadas de acordo com o arquivo de
     * configuração config-bankdata.php, ou null se ocorrer um erro.
     */
    private function formattedBankingInfo($bank, $branch, $account, $operation)
    {
        $out = [];
        if (!$bank || !$branch || !$account) {
            return null;
        }
        $rules = $this->config["config-bankdata"]["byNumber"];
        if (!isset($rules[$bank])) {
            return null;
        }
        $out["bank"] = $bank;
        $rules = $rules[$bank];
        if (!$this->verifyField($rules["branch"], $branch)) {
            return null;
        }
        $out["branch"] = $this->formattedField($rules["branch"], $branch);
        if (!$this->verifyField($rules["account"], $account)) {
            return null;
        }
        $out["account"] = $this->formattedField($rules["account"], $account);
        if (isset($rules["operation"])) {
            if (!$operation) {
                return null;
            }
            if (!$this->verifyCannedField($rules["operation"], $operation)) {
                return null;
            }
            $out["operation"] = str_pad($operation,
                                        $rules["operation"]["length"], "0",
                                        STR_PAD_LEFT);;
        }
        return $out;
    }

    /**
     * Formata um campo de informações bancárias separando valor e dígito, e
     * aplicando transformações ao dígito se especificado na regra.
     */
    private function formattedField($rules, $value)
    {
        $out = ["formatted" => ""];
        $value = preg_replace("/[\.\_\:\s\-]/", "", trim($value));
        if (isset($rules["digit"])) {
            $digit = substr($value, -1);
            if (is_array($rules["digit"]) && isset($rules["digit"]["map"])) {
                if (isset($rules["digit"]["map"][$digit])) {
                    $digit = $rules["digit"]["map"][$digit];
                }
            }
            $out["digit"] = $digit;
            $value = substr($value, 0, -1);
            $out["formatted"] = "-" . $digit;
        }
        if (isset($rules["prefix"]) &&
            (strlen($value) == $rules["prefix"]["totalLength"])) {
            $value = substr($value, ($rules["prefix"]["totalLength"] - $rules["length"]));
        }
        if (strlen($value) > $rules["length"]) {
            $value = substr($value, -$rules["length"]);
        } else {
            $value = str_pad($value, $rules["length"], "0", STR_PAD_LEFT);
        }
        $out["value"] = $value;
        $out["formatted"] = $value . $out["formatted"];
        return $out;
    }

    /**
     * Valida e retorna os parâmetros da URL. Recebe um dicionário com os nomes
     * e tipos dos parâmetros. Tipos possíveis: date, int, intArray, string.
    */
    private function getURLParameters($list)
    {
        $parameters = [];
        if (empty($this->data)) {
            return $parameters;
        }
        $app = App::i();
        foreach ($list as $name => $type) {
            if (!isset($this->data[$name]) || empty($this->data[$name])) {
                continue;
            }
            switch ($type) {
                case "date":
                    if (!preg_match("/^[0-9]{4}-[0-9]{1,2}-[0-9]{1,2}$/",
                        $this->data[$name])) {
                        throw new \Exception("O formato da data em $name é inválido.");
                    } else {
                        $date = new DateTime($this->data[$name]);
                        $parameters[$name] = $date->format("Y-m-d 00:00");
                    }
                    break;
                case "int":
                    if (!is_numeric($this->data[$name])) {
                        throw new Exception("Parâmetro inválido em $name.");
                    }
                    $parameters[$name] = $this->data[$name];
                    break;
                case "intArray":
                    $array = explode(",", $this->data[$name]);
                    foreach ($array as $element) {
                        if (!is_numeric($element)) {
                            throw new Exception("Parâmetro inválido em $name: $element.");
                        }
                    }
                    $parameters[$name] = $array;
                    break;
                case "string":
                    $parameters[$name] = $this->data[$name];
                break;
                default:
                    $app->log->warning("Tipo de parâmetro desconhecido: $type.");
            }
        }
        return $parameters;
    }

    /*
     * Função para retornar o número do banco, levando como base de pesquisa o nome do banco
     * Todos os textos que entram pelo parâmetro $bankName, são primeiro colocados em lowercase em seguida a primeira letra
     * de cada palavra e passado para upercase
     *
     */
    private function numberBank($bankName)
    {
        $bankList = $this->config["config-bankdata"]["byName"];
        $return = "";
        $normdBank = Normalizer::normalize($bankName, Normalizer::FORM_D);
        $normdBank = strtolower(preg_replace("/[^a-z0-9 ]/i", "", $normdBank));
        foreach ($bankList as $key => $value) {
            if ($key == $normdBank) {
                $return = $value;
                break;
            }
        }
        return $return;
    }

    /**
     * Pega o valor da config e do mapeamento e monta a string.
     * Sempre será respeitado os valores de tamanho de string e tipo que estão no arquivo de config
     *
     */
    private function mountTxt($array, $mapped, $txt_data, $register, $complement, $app)
    {
        if ($complement) {
            foreach ($complement as $key => $value) {
                $array[$key]['default'] = $value;
            }
        }

        foreach ($array as $key => $value) {
            if ($value['field_id']) {
                if (is_callable($mapped[$key])) {
                    $data = $mapped[$key];
                    $value['default'] = $data($register);
                    $value['field_id'] = null;
                    $txt_data .= $this->createString($value);
                    $value['default'] = null;
                    $value['field_id'] = $value['field_id'];

                    if ($key == "VALOR_INTEIRO") {
                        $inteiro = 0;

                        if ($key == "VALOR_INTEIRO") {
                            $inteiro = $data($register);
                        }

                        $valor = $inteiro;

                        $_SESSION['valor'] = $_SESSION['valor'] + $valor;
                    }
                }
            } else {
                $txt_data .= $this->createString($value);
            }
        }
        return $txt_data;
    }

    /**
     * Obtém o valor de um campo para o qual estejam configuradas fontes
     * alternativas no config-bankdata.php. Atualmente suporta apenas máscaras
     * de tamanho fixo onde uma seqüência contígua de caracteres "o" marca a
     * localização da informação.
     *
     * O parâmetro sources deve usar as chaves usadas na configuração.
     *
     * Retorna null se não for possível encontrar um valor.
     *
     * Exemplo de configuração no parâmetro rules:
     * [
     *     "alternateSources" => ["account" => "oooxxxxxxxxx"],
     *     "length" => 3
     * ]
     * Neste caso, o parâmetro $sources deve conter a chave "account" associada
     * a uma string que filtrada tenha 12 caracteres para que o valor seja
     * encontrado.
     */
    private function obtainField($rules, $sources)
    {
        if (!isset($rules["alternateSources"])) {
            return null;
        }
        foreach ($rules["alternateSources"] as $source => $mask) {
            if (!isset($sources[$source])) {
                continue;
            }
            $candidateSource = preg_replace("/[\.\_\:\s\-]/", "",
                                            trim($sources[$source]));
            if ((strlen($candidateSource) == strlen($mask))) {
                $start = strcspn($mask, "o");
                $end = strspn($mask, "o", $start);
                if ($end == $rules["length"]) {
                    $value = substr($candidateSource, $start, $end);
                    if (isset($rules["values"]) &&
                        !in_array($value, $rules["values"])) {
                        return null;
                    }
                    return $value;
                }
            }
        }
        return null;
    }

	/**
     * Executa as validações de informações bancárias com base no arquivo de
     * configuração config-bankdata.php.
     * Os códigos VALIDATION_FAILED_* podem voltar combinados em um bitmap.
     */
    private function verifyBankingInfo($bank, $branch, $account, &$operation)
    {
        if (!$bank || !$branch || !$account) {
            return Remessas::VALIDATION_MISSING_DATA;
        }
        $rules = $this->config["config-bankdata"]["byNumber"];
        if (!isset($rules[$bank])) {
            return Remessas::VALIDATION_NORULE;
        }
        $rules = $rules[$bank];
        $result = Remessas::VALIDATION_PASSED;
        if (!$this->verifyField($rules["branch"], $branch)) {
            $result |= Remessas::VALIDATION_FAILED_BRANCH;
        }
        if (!$this->verifyField($rules["account"], $account)) {
            $result |= Remessas::VALIDATION_FAILED_ACCOUNT;
        }
        if (isset($rules["operation"])) {
            if (!$operation && !isset($this->data["strict"])) {
                $operation = $this->obtainField($rules["operation"], [
                    "account" => $account,
                    "branch" => $branch
                ]);
                if (!$operation) {
                    return Remessas::VALIDATION_MISSING_DATA;
                }
            }
            if (!$this->verifyCannedField($rules["operation"], $operation)) {
                $result |= Remessas::VALIDATION_FAILED_OPERATION;
            }
        }
        return $result;
    }

    /**
     * Valida um campo de informações bancárias que só aceita uma lista de
     * valores predefinidos (atualmente só a operação da CEF).
    */
    private function verifyCannedField($rules, $value)
    {
        $strict = isset($this->data["strict"]);
        $value = trim($value);
        if (!$strict) {
            $value = str_pad($value, $rules["length"], "0", STR_PAD_LEFT);
        }
        if (!in_array($value, $rules["values"])) {
            return false;
        }
        return true;
    }

    /**
     * Valida um campo de informações bancárias (agência ou conta).
     */
    private function verifyField($rules, $value)
    {
        $strict = isset($this->data["strict"]);
        // corta todos os supérfluos
        $value = preg_replace("/[\.\_\:\s\-]/", "", trim($value));
        // verifica regra detalhada
        if (is_array($rules)) {
            $len = $rules["length"];
            $maxLen = $len;
            // aplica regra de dígito se existir
            if (isset($rules["digit"])) {
                ++$maxLen;
            }
            // aplica regra de prefixo se existir e tamanho sugerir prefixo
            if (isset($rules["prefix"]) &&
                !$this->verifyFieldSize($value, $maxLen, $strict)) {
                $prefix = $rules["prefix"];
                $lenValue = strlen($value);
                if ($lenValue != ($prefix["totalLength"] + ($maxLen - $len))) {
                    $extra = substr($value, 0, ($lenValue - $maxLen));
                    return (strspn($extra, "0") == ($lenValue - $maxLen));
                }
                $found = false;
                foreach ($prefix["values"] as $p) {
                    if (str_starts_with($value, $p)) {
                        $found = true;
                        $maxLen += strlen($p);
                        break;
                    }
                }
                if (!$found) {
                    $extra = substr($value, 0, ($lenValue - $maxLen));
                    return (strspn($extra, "0") == ($lenValue - $maxLen));
                }
            }
            if (!$this->verifyFieldSize($value, $maxLen, $strict)) {
                return false;
            }
            // aplica regra de valores fixos se existir
            if (isset($rules["values"])) {
                return $this->verifyCannedField($rules, $value);
            }
            // aplica regra regex se existir
            if (isset($rules["regex"])) {
                // aqui já passou pelo verifyFieldSize então pode truncar
                return preg_match($rules["regex"], substr($value, -$maxLen));
            }
            return true;
        }
        // trata regra como regex
        return preg_match($rules, $value);
	}

    /**
     * Valida o tamanho de um campo. Se strict for verdadeiro, aceita apenas o
     * próprio tamanho passado. Caso contrário aceita tamanhos menores e maiores
     * desde que os caracteres sobrando à esquerda sejam todos zero.
     */
    private function verifyFieldSize($value, $size, $strict)
    {
        $len = strlen($value);
        if ($strict) {
            return ($len == $size);
        }
        if ($len <= $size) {
            return true;
        }
        $extra = substr($value, 0, ($len - $size));
        return (strspn($extra, "0") == ($len - $size));
    }

    /*
     * Normaliza uma string
     */
    private function normalizeString($valor)
    {
        $valor = Normalizer::normalize($valor, Normalizer::FORM_D);
        return preg_replace('/[^a-z0-9 ]/i', '', $valor);
    }

    /** #########################################################################
     * Funções para o MCI460
     */

    private function exportMCI460($opportunities, $startDate, $finishDate)
    {
        $app = App::i();
        $config = $this->config["config-mci460"];
        if (!isset($config["condition"])) {
            throw new Exception("Configuração inválida: \"condition\" não configurada.");
        }
        $newline = "\r\n";
        set_time_limit(0);
        // inicializa contadores
        $nLines = 1;
        $nClients = 0;
        // gera o header
        $out = $this->mci460Header($config) . $newline;
        $opportunityIDs = [];
        // percorre as oportunidades
        foreach ($opportunities as $opportunity) {
            // pega inscrições via DQL seguindo recomendações do Doctrine para grandes volumes
            if ($startDate != null) {
                $dql = "SELECT e FROM MapasCulturais\Entities\Registration e
                        WHERE e.status IN (1, 10) AND e.opportunity = :oppId AND
                              e.sentTimestamp >=:startDate AND
                              e.sentTimestamp <= :finishDate";
                $query = $app->em->createQuery($dql);
                $query->setParameters([
                    'oppId' => $opportunity->id,
                    'startDate' => $startDate,
                    'finishDate' => $finishDate,
                ]);
            } else {
                $dql = "SELECT e FROM MapasCulturais\Entities\Registration e
                                 WHERE e.status IN (1, 10) AND e.opportunity=:oppId";
                $query = $app->em->createQuery($dql);
                $query->setParameters(["oppId" => $opportunity->id]);
            }
            $registrations = $query->iterate();
            /**
             * Mapeamento de fielsds_id pelo label do campo
             */
            $this->registerRegistrationMetadata($opportunity);
            // processa inscrições
            $clientsBefore = $nClients;
            while ($registration = $registrations->next()[0]) {
                // testa se é desbancarizado
                if (!$this->mci460Thunk2($config["condition"], $config["fieldMap"], $registration)) {
                    continue;
                }
                ++$nClients;
                $details = $this->mci460Details($config, $registration, [
                    "sequencialCliente" => $nClients,
                    "agencia" => 6666, // placeholder
                    "dvAgencia" => "X", // placeholder
                    "grupoSetex" => 66, // placeholder
                    "dvGrupoSetex" => "X", // placeholder
                ]);
                $nLines += sizeof($details);
                $out .= implode($newline, $details) . $newline;
                $app->em->clear();
            }
            if ($nClients > $clientsBefore) {
                $opportunityIDs[] = $this->createString([
                    "default" => $opportunity->id,
                    "length" => 3,
                    "type" => "int",
                ]);
            }
        }
        ++$nLines;
        $out .= $this->mci460Trailer($config, [
            "totalClientes" => $nClients,
            "totalRegistros" => $nLines,
        ]) . $newline;
        /**
         * cria o arquivo no servidor e insere o conteuto da váriavel $out
         */
        $fileName = "mci460-" . (new DateTime())->format('Ymd') . "-op" .
                    implode("-", $opportunityIDs) . "-" .
                    md5(json_encode($out)) . '.txt';
        $dir = PRIVATE_FILES_PATH . "aldirblanc/inciso1/remessas/mci460/";
        $path = $dir . $fileName;
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $stream = fopen($path, "w");
        fwrite($stream, $out);
        fclose($stream);
        header("Content-Type: text/utf-8");
        header("Content-Disposition: attachment; filename=" . $fileName);
        header("Pragma: no-cache");
        readfile($path);
        return;
    }

    private function addressReport($opportunities, $startDate, $finishDate)
    {
        $app = App::i();
        set_time_limit(0);
        $header = ["Inscrição", "Nome", "Logradouro", "Número", "Complemento",
                "Bairro", "Município", "Estado", "CEP"];
        $report = [];
        $opportunityIDs = [];
        $config = $this->config["config-mci460"];
        $address = $config["fieldMap"]["endereco"];
        foreach ($opportunities as $opportunity) {
            // pega inscrições via DQL seguindo recomendações do Doctrine para grandes volumes
            if (isset($startDate)) {
                $dql = "SELECT e FROM MapasCulturais\Entities\Registration e
                        WHERE e.status IN (1, 10) AND e.opportunity = :oppId AND
                            e.sentTimestamp >=:startDate AND
                            e.sentTimestamp <= :finishDate";
                $query = $app->em->createQuery($dql);
                $query->setParameters([
                    'oppId' => $opportunity->id,
                    'startDate' => $startDate,
                    'finishDate' => $finishDate,
                ]);
            } else {
                $dql = "SELECT e FROM MapasCulturais\Entities\Registration e
                                WHERE e.status IN (1, 10) AND e.opportunity=:oppId";
                $query = $app->em->createQuery($dql);
                $query->setParameters(["oppId" => $opportunity->id]);
            }
            $registrations = $query->iterate();
            /**
             * Mapeamento de fielsds_id pelo label do campo
             */
            $this->registerRegistrationMetadata($opportunity);
            $linesBefore = sizeof($report);
            while ($registration = $registrations->next()[0]) {
                if (!$this->mci460Thunk2($config["condition"], $config["fieldMap"], $registration)) {
                    continue;
                }
                $addressFields = [];
                $source = $registration->$address;
                if (is_array($source)) {
                    $addressFields[] = $source["En_Nome_Logradouro"];
                    $addressFields[] = $source["En_Num"];
                    $addressFields[] = isset($source["En_Complemento"]) ? $source["En_Complemento"] : "";
                    $addressFields[] = $source["En_Bairro"];
                    $addressFields[] = $source["En_Municipio"];
                    $addressFields[] = $source["En_Estado"];
                    $addressFields[] = $source["En_CEP"];
                } else {
                    $addressFields[] = $source->En_Nome_Logradouro;
                    $addressFields[] = $source->En_Num;
                    $addressFields[] = isset($source->En_Complemento) ? $source->En_Complemento : "";
                    $addressFields[] = $source->En_Bairro;
                    $addressFields[] = $source->En_Municipio;
                    $addressFields[] = $source->En_Estado;
                    $addressFields[] = $source->En_CEP;
                }
                $report[] = array_merge([$registration->number,
                                        $registration->field_22], $addressFields);
                $app->em->clear();
            }
            if (sizeof($report) > $linesBefore) {
                $opportunityIDs[] = $this->createString([
                    "default" => $opportunity->id,
                    "length" => 3,
                    "type" => "int",
                ]);
            }
        }
        /**
         * cria o arquivo no servidor e insere o $header e as entradas do $report
         */
        $fileName = "addressReport-" . (new DateTime())->format('Ymd') . "-op" .
                    implode("-", $opportunityIDs) . "-" .
                    md5(json_encode(array_merge([$header], $report))) . '.csv';
        $dir = PRIVATE_FILES_PATH . "aldirblanc/inciso1/remessas/generics/";
        $path = $dir . $fileName;
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $stream = fopen($path, "w");
        $csv = Writer::createFromStream($stream);
        $csv->insertOne($header);
        foreach ($report as $line) {
            $csv->insertOne($line);
        }
        header("Content-Type: application/csv");
        header("Content-Disposition: attachment; filename=" . $fileName);
        header("Pragma: no-cache");
        readfile($path);
        fclose($stream);
        return;
    }

    private function mci460Thunk2($func, $parm0, $parm1)
    {
        if (!method_exists($this, $func)) {
            throw new Exception("Configuração inválida: $func não existe.");
        }
        return $this->$func($parm0, $parm1);
    }

    private function mci460ConditionES($fieldMap, $registration)
    {
        $hasAccount = $fieldMap["hasAccount"];
        $wantsAccount = $fieldMap["wantsAccount"];
        if ($this->config["exportador_requer_homologacao"] &&
            !in_array($registration->consolidatedResult, [
                "10", "homologado, validado por Dataprev"
        ])) {
            return false;
        }
        return (($registration->$hasAccount != "SIM") &&
                ($registration->$wantsAccount != null) &&
                (str_starts_with($registration->$wantsAccount, "CONTA")));
    }

    private function mci460ConditionDetail04ES($config, $registration)
    {
        $field = $config["fieldMap"]["conjuge"];
        foreach ($registration->$field as $member) {
            if (property_exists($member, "relationship") &&
                ($member->relationship === "1")) {
                return true;
            }
        }
        return false;
    }

    private function mci460ConditionDetail09ES($config, $registration) {
        $field = $config["fieldMap"]["email"];
        return (strlen($registration->$field) > 0);
    }

    private function mci460AddressES($fieldSpec, $address)
    {
        $out = "";
        $components = [];
        if (is_array($address)) {
            $components["logradouro"] = $address["En_Nome_Logradouro"] . ", " .
                                        $address["En_Num"];
            $components["logradouro"] .= isset($address["En_Complemento"]) ?
                                         (", " . $address["En_Complemento"]) : "";
            $components["distritoBairro"] = $address["En_Bairro"];
            $components["cep"] = $address["En_CEP"];
        } else { // caminho não testado, todos os endereços no teste são dictionary
            $components["logradouro"] = $address->En_Nome_Logradouro . ", " .
                                        $address->En_Num;
            $components["logradouro"] .= isset($address->En_Complemento) ?
                                         (", " . $address->En_Complemento) : "";
            $components["distritoBairro"] = $address->En_Bairro;
            $components["cep"] = $address->En_CEP;
        }
        foreach ($fieldSpec["fields"] as $field) {
            $field["default"] = $components[$field["name"]];
            $out .= $this->createString($field);
        }
        return $out;
    }

    private function mci460DateFormatDDMMYYYY($value) {
        return implode("", array_reverse(explode("-", $value)));
    }

    private function mci460DateDDMMYYYY() {
        return (new DateTime())->format('dmY');
    }

    private function mci460NationalityES($value)
    {
        if (($value != null) && !str_starts_with($value, "Estrangeiro"))
            return 1;
        return 0;
    }

    private function mci460PhoneES($fieldSpec, $phone)
    {
        $out = "";
        $components = [];
        $phone = preg_replace("/[^0-9\(\)]/", "", $phone);
        if (strlen($phone) < 12) {
            $components["ddd"] = "";
            $components["telefone"] = "";
        } else {
            $components["ddd"] = substr($phone, 0, 4);
            $components["telefone"] = substr($phone, 4);
        }
        foreach ($fieldSpec["fields"] as $field) {
            $field["default"] = $components[$field["name"]];
            $out .= $this->createString($field);
        }
        return $out;
    }

    private function mci460SpouseES($fieldSpec, $family)
    {
        $out = "";
        foreach ($family as $member) {
            if (!property_exists($member, "relationship") ||
                ($member->relationship != "1")) {
                continue;
            }
            foreach ($fieldSpec["fields"] as $field) {
                if (!isset($field["default"])) {
                    $fieldName = $field["name"];
                    $field["default"] = $member->$fieldName;
                }
                $out .= $this->createString($field);
            }
            break;
        }
        return $out;
    }

    /**
     * Gera os detalhes do arquivo MCI460.
     */
    private function mci460Details($config, $registration, $extraData)
    {
        $out = [];
        // itera sobre definições de detalhes
        foreach ($config["details"] as $detail) {
            // pula detalhes cuja condição o registro não atende
            if (isset($detail["condition"])) {
                if (!$this->mci460Thunk2($detail["condition"], $config,
                                         $registration)) {
                    continue;
                }
            }
            $line = "";
            // itera sobre definições de campos
            foreach ($detail["fields"] as $field) {
                // processa campos variáveis
                if (!isset($field["default"])) {
                    if ($field["type"] === "meta") {
                        $line .= $this->mci460MetaField($config, $field, $registration);
                        continue;
                    }
                    $fieldName = $field["name"];
                    // campos externos (por exemplo, o contador de clientes)
                    if (!isset($config["fieldMap"][$fieldName])) {
                        $field["default"] = $extraData[$fieldName];
                    } else { // campos do banco de dados
                        $fieldName = $config["fieldMap"][$fieldName];
                        $field["default"] = isset($field["function"]) ?
                                            $this->mci460Thunk2($field["function"], $registration->$fieldName, null) :
                                            $registration->$fieldName;
                    }
                }
                $line .= $this->createString($field);
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * Gera o cabeçalho do arquivo MCI460.
     */
    private function mci460Header($config)
    {
        $out = "";
        foreach ($config["header"] as $field) {
            if (!isset($field["default"])) {
                if (isset($field["function"])) {
                    $field["default"] = $this->mci460Thunk2($field["function"],
                                                            null, null);
                } else {
                    throw new Exception("Configuração inválida: $field");
                }
            }
            $out .= $this->createString($field);
        }
        return $out;
    }

    private function mci460MetaField($config, $metafieldConfig, $registration)
    {
        $out = "";
        $metaname = $metafieldConfig["name"];
        if (isset($metafieldConfig["function"])) {
            $field = $config["fieldMap"][$metaname];
            return $this->mci460Thunk2($metafieldConfig["function"],
                                       $metafieldConfig, $registration->$field);
        }
        // caminho não testado a seguir; todos os metacampos atualmente têm sua própria função geradora
        foreach ($metafieldConfig["fields"] as $field) {
            if (!isset($field["default"])) {
                $fieldName = $field["name"];
                if (!isset($config["fieldMap"][$metaname])) {
                    $field["default"] = $registration->$fieldName;
                } else {
                    $field["default"] = $registration->$metaname->$fieldName;
                }
            }
            $out[] .= $this->createString($field);
        }
        return $out;
    }

    /**
     * Gera o rodapé do arquivo MCI460.
     */
    private function mci460Trailer($config, $counters)
    {
        $out = "";
        foreach ($config["trailer"] as $field) {
            if (!isset($field["default"])) {
                $field["default"] = $counters[$field["name"]];
            }
            $out .= $this->createString($field);
        }
        return $out;
    }

}
