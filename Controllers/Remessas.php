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

    /**
     * Implementa um exportador genérico, que de momento tem a intenção de antender os municipios que não vão enviar o arquivo de remessa
     * diretamente ao banco do Brasil.
     * http://localhost:8080/remessas/genericExportInciso2/opportunity:12/
     *
     * O Parâmetro opportunity e identificado e incluido no endpiont automáricamente
     *
     */
    public function ALL_genericExportInciso2()
    {

        /**
         * Verifica se o usuário está autenticado
         */
        $this->requireAuthentication();
        $app = App::i();

        /**
         * Pega os dados da configuração
         */

        $csv_conf = $this->config['csv_generic_inciso2'];
        $status = $csv_conf['parameters_default']['status'];
        $categories = $csv_conf['categories'];
        $header = $csv_conf['header'];

        /**
         * Pega os parâmetros do endpoint
         */
        if (!empty($this->data)) {
            //Pega a oportunidade do endpoint
            if (!isset($this->data['opportunity']) || empty($this->data['opportunity'])) {
                throw new Exception("Informe a oportunidade! Ex.: opportunity:2");

            } elseif (!is_numeric($this->data['opportunity']) || !in_array($this->data['opportunity'], $this->config['inciso2_opportunity_ids'])) {
                throw new Exception("Oportunidade inválida");

            } else {
                $opportunity_id = $this->data['opportunity'];
            }
        }

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
         * Busca as inscrições com status 10 (Selecionada)
         * lembrando que o botão para exportar esses dados, so estrá disponível se existir inscrições nesse status
         */
        $dql = "SELECT e FROM MapasCulturais\Entities\Registration e WHERE e.status = :status AND e.opportunity = :opportunity_Id";

        $query = $app->em->createQuery($dql);
        $query->setParameters([
            'opportunity_id' => $opportunity_id,
            'status' => $status,
        ]);

        $registrations = $query->getResult();

        if (empty($registrations)) {
            echo "Não foram encontrados registros.";
            die();
        }

        /**
         * Mapeamento de fields_id pelo label do campo
         */
        foreach ($opportunity->registrationFieldConfigurations as $field) {
            $field_labelMap["field_" . $field->id] = trim($field->title);
        }

        /**
         * Monta a estrutura de field_id's e as coloca dentro de um array organizado para a busca dos dados
         *
         * Será feito uma comparação de string, coloque no arquivo de configuração
         * exatamente o texto do label desejado
         */
        $fieldsID = [];
        foreach ($csv_conf['fields'] as $key_csv_conf => $field) {
            if (is_array($field)) {
                $fields = array_unique($field);
                if (count($fields) == 1) {
                    foreach ($field as $key => $value) {
                        $field_temp = array_keys($field_labelMap, $value);

                    }

                } else {
                    $field_temp = [];
                    foreach ($field as $key => $value) {
                        $field_temp[] = array_search(trim($value), $field_labelMap);

                    }
                }
                $fieldsID[$key_csv_conf] = $field_temp;

            } else {
                $field_temp = array_search(trim($field), $field_labelMap);
                $fieldsID[$key_csv_conf] = $field_temp ? $field_temp : $field;

            }
        }

        /**
         * Busca os dados em seus respecitivos registros com os fields mapeados
         */
        $mappedRecords = [
            'CPF' => function ($registrations) use ($fieldsID, $categories) {
                if (in_array($registrations->category, $categories['CPF'])) {
                    $field_id = $fieldsID['CPF'];
                    return str_replace(['.', '-'], '', $registrations->$field_id);
                } else {
                    return 0;
                }
            },
            'NOME_SOCIAL' => function ($registrations) use ($fieldsID, $categories) {
                if (in_array($registrations->category, $categories['CPF'])) {
                    $field_id = $fieldsID['NOME_SOCIAL'];
                    return $registrations->$field_id;
                } else {
                    return "";
                }
            },
            'CNPJ' => function ($registrations) use ($fieldsID, $categories) {
                if (in_array($registrations->category, $categories['CNPJ'])) {
                    $field_id = $fieldsID['CNPJ'];
                    if (is_array($field_id)) {
                        $result = "";
                        foreach ($field_id as $key => $value) {
                            if ($registrations->$value) {
                                $result = str_replace(['.', '-', '/'], '', $registrations->$value);
                            }
                        }
                        return $result;
                    } else {
                        return str_replace(['.', '-', '/'], '', $registrations->$field_id);
                    }
                } else {
                    return 0;
                }
            },
            'RAZAO_SOCIAL' => function ($registrations) use ($fieldsID, $categories) {
                if (in_array($registrations->category, $categories['CNPJ'])) {
                    $field_id = $fieldsID['RAZAO_SOCIAL'];
                    if (is_array($field_id)) {
                        $result = "";
                        foreach ($field_id as $key => $value) {
                            if ($registrations->$value) {
                                $result = $registrations->$value;
                            }
                        }
                        return $result;
                    } else {
                        return $registrations->$field_id;
                    }
                } else {
                    return "";
                }
            },
            'LOGRADOURO' => function ($registrations) use ($fieldsID) {
                $field_id = $fieldsID['LOGRADOURO'];
                return $registrations->$field_id['En_Nome_Logradouro'];
            },
            'NUMERO' => function ($registrations) use ($fieldsID) {
                $field_id = $fieldsID['NUMERO'];
                return preg_replace("/[^0-9]/", "", $registrations->$field_id['En_Num']);
            },
            'COMPLEMENTO' => function ($registrations) use ($fieldsID) {
                $field_id = $fieldsID['COMPLEMENTO'];
                return $registrations->$field_id['En_Complemento'];
            },
            'BAIRRO' => function ($registrations) use ($fieldsID) {
                $field_id = $fieldsID['BAIRRO'];
                return $registrations->$field_id['En_Bairro'];
            },
            'MUNICIPIO' => function ($registrations) use ($fieldsID) {
                $field_id = $fieldsID['MUNICIPIO'];
                return $registrations->$field_id['En_Municipio'];
            },
            'CEP' => function ($registrations) use ($fieldsID) {
                $field_id = $fieldsID['CEP'];
                return preg_replace("/[^0-9]/", "", $registrations->$field_id['En_CEP']);
            },
            'ESTADO' => function ($registrations) use ($fieldsID) {
                $field_id = $fieldsID['ESTADO'];
                return $registrations->$field_id['En_Estado'];
            },
            'NUM_BANCO' => function ($registrations) use ($fieldsID) {
                $field_id = $fieldsID['NUM_BANCO'];
                return $this->numberBank($registrations->$field_id);
            },
            'TIPO_CONTA_BANCO' => function ($registrations) use ($fieldsID) {
                $field_id = $fieldsID['TIPO_CONTA_BANCO'];
                return $registrations->$field_id;
            },
            'AGENCIA_BANCO' => function ($registrations) use ($fieldsID) {
                $field_id = $fieldsID['AGENCIA_BANCO'];
                return preg_replace("/[^0-9]/", "", $registrations->$field_id);
            },
            'CONTA_BANCO' => function ($registrations) use ($fieldsID) {
                $field_id = $fieldsID['CONTA_BANCO'];
                return preg_replace("/[^0-9]/", "", $registrations->$field_id);
            },
            'OPERACAO_BANCO' => function ($registrations) use ($fieldsID) {
                $field_id = $fieldsID['OPERACAO_BANCO'];
                return preg_replace("/[^0-9]/", "", $registrations->$field_id);
            },
            'VALOR' => $fieldsID['VALOR'],
            'INSCRICAO_ID' => function ($registrations) use ($fieldsID) {
                return preg_replace("/[^0-9]/", "", $registrations->number);

            },
            'INCISO' => function ($registrations) use ($fieldsID) {
                $field_id = $fieldsID['INCISO'];
                return $field_id;
            },

        ];

        //Itera sobre os dados mapeados
        $csv_data = [];
        foreach ($registrations as $key_registration => $registration) {
            foreach ($mappedRecords as $key_fields => $field) {
                if (is_callable($field)) {
                    $csv_data[$key_registration][$key_fields] = $field($registration);

                } else if (is_string($field) && strlen($field) > 0) {
                    if ($registration->$field) {
                        $csv_data[$key_registration][$key_fields] = $registration->$field;
                    } else {
                        $csv_data[$key_registration][$key_fields] = $field;
                    }

                } else {
                    if (strstr($field, 'field_')) {
                        $csv_data[$key_registration][$key_fields] = null;
                    } else {
                        $csv_data[$key_registration][$key_fields] = $field;
                    }

                }
            }
        }

        /**
         * Salva o arquivo no servidor e faz o dispatch dele em um formato CSV
         * O arquivo e salvo no deretório docker-data/private-files/aldirblanc/inciso2/remessas
         */
        $file_name = 'inciso2-genCsv-' . md5(json_encode($csv_data)) . '.csv';

        $dir = PRIVATE_FILES_PATH . 'aldirblanc/inciso2/remessas/generics/';

        $patch = $dir . $file_name;

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $stream = fopen($patch, 'w');

        $csv = Writer::createFromStream($stream);

        $csv->insertOne($header);

        foreach ($csv_data as $key_csv => $csv_line) {
            $csv->insertOne($csv_line);
        }

        header('Content-Type: application/csv');
        header('Content-Disposition: attachment; filename=' . $file_name);
        header('Pragma: no-cache');
        readfile($patch);
    }

    /**
     * Implementa o exportador TXT no modelo CNAB 240, para envio de remessas ao banco do Brasil inciso1
     *
     *
     */
    public function ALL_exportCnab240Inciso1()
    {

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
        $status = $default['status'];
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
            WHERE e.status = :status AND
            e.opportunity = :opportunity_Id AND
            e.sentTimestamp >=:startDate AND
            e.sentTimestamp <= :finishDate";

            $query = $app->em->createQuery($dql);
            $query->setParameters([
                'opportunity_Id' => $opportunity_id,
                'status' => $status,
                'startDate' => $startDate,
                'finishDate' => $finishDate,
            ]);

            $registrations = $query->getResult();

        } else {
            $dql = "SELECT e FROM MapasCulturais\Entities\Registration e
            WHERE e.status = :status AND
            e.opportunity = :opportunity_Id";

            $query = $app->em->createQuery($dql);
            $query->setParameters([
                'opportunity_Id' => $opportunity_id,
                'status' => $status,
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
                exit();

            },
            'CONTA_DIGITO' => function ($registrations) use ($header1) {
                $result = "";
                $field_id = $header1['CONTA_DIGITO'];
                $value = $this->normalizeString($field_id['default']);
                $result = is_string($value) ? strtoupper($value) : $value;
                return $result;

            },
            'USO_BANCO_20' => '',
            'NOME_EMPRESA' => '',
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
                exit();

            },
            'CONTA_DIGITO' => function ($registrations) use ($header1) {
                $result = "";
                $field_id = $header1['CONTA_DIGITO'];
                $value = $this->normalizeString($field_id['default']);
                $result = is_string($value) ? strtoupper($value) : $value;
                return $result;

            },
            'USO_BANCO_51' => '',
            'NOME_EMPRESA' => '',
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
            'BEN_AGENCIA' => function ($registrations) use ($detahe1) {
                $field_id = $detahe1['BEN_AGENCIA']['field_id'];
                $age = $registrations->$field_id;

                if (strlen($age) > 4) {
                    $result = substr($age, 0, 4);
                } else {
                    $result = $age;
                }
                return $result;
            },
            'BEN_AGENCIA_DIGITO' => function ($registrations) use ($detahe1) {
                $result = "";
                $field_id = $detahe1['BEN_AGENCIA_DIGITO']['field_id'];
                $dig = $registrations->$field_id;
                if (strlen($dig) > 4) {
                    $result = substr($dig, -1);
                } else {
                    $result = "";
                }

                return is_string($result) ? strtoupper($result) : $result;
            },
            'BEN_CONTA' => function ($registrations) use ($detahe1, $default) {
                $temp = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                $numberBank = $this->numberBank($registrations->$temp);
                $temp = $detahe1['TIPO_CONTA']['field_id'];
                $typeAccount = $registrations->$temp;

                $field_id = $detahe1['BEN_CONTA']['field_id'];
                $temp = $detahe1['BEN_CONTA_DIGITO']['field_id'];

                $account = $registrations->$field_id;

                $result = "";
                if ($numberBank == '001' && $typeAccount == $default['typesAccount']['poupanca']) {

                    if (substr($account, 0, 3) != "510") {
                        $result = "510" . $account;
                    } else {

                        $result = $account;

                    }
                } else {

                    $result = $registrations->$field_id;
                }

                if($temp === $field_id){
                    return substr($result, 0, -1); // Remove o ultimo caracter. Intende -se que o ultimo caracter é o DV da conta

                }else{
                    return $result;

                }

            },
            'BEN_CONTA_DIGITO' => function ($registrations) use ($detahe1, $default) {
                $temp = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                $field_id = $detahe1['BEN_CONTA']['field_id'];

                $numberBank = $this->numberBank($registrations->$temp);

                $temp = $detahe1['TIPO_CONTA']['field_id'];
                $typeAccount = $registrations->$temp;

                $temp = $detahe1['BEN_CONTA_DIGITO']['field_id'];
                $account = preg_replace('/[^0-9]/i', '', $registrations->$temp);

                $dig = substr($account, -1);

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
                $result = substr($registrations->$field_id, 0, $detahe1['BEN_NOME']['length']);
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
            'VALOR_INTEIRO' => function ($registrations) use ($detahe1) {
                $valor = '100,98';
                $valor = preg_replace('/[^0-9]/i', '', $valor);

                return $valor;
            },
            'USO_BANCO_88' => '',
            'USO_BANCO_89' => '',
            'USO_BANCO_90' => '',
            'CODIGO_FINALIDADE_TED' => function ($registrations) use ($detahe1) {
                $temp = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                $numberBank = $this->numberBank($registrations->$temp);
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

                if (strlen($result) > $length) {
                    $app->log->info($registrations->number . " Campo BEN_ENDERECO_LOGRADOURO Header 2 maior que o permitido o valor foi truncado. Máximo permitido = " . $length);
                }

                $result = substr($result, 0, $length);

                return $result;

            },
            'BEN_ENDERECO_NUMERO' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_NUMERO']['field_id'];
                $length = $detahe2['BEN_ENDERECO_NUMERO']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Num'];

                if (strlen($result) > $length) {
                    $app->log->info($registrations->number . " Campo BEN_ENDERECO_NUMERO Header 2 maior que o permitido o valor foi truncado. Máximo permitido = " . $length);
                }

                $result = substr($result, 0, $length);

                return $result;
            },
            'BEN_ENDERECO_COMPLEMENTO' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_COMPLEMENTO']['field_id'];
                $length = $detahe2['BEN_ENDERECO_COMPLEMENTO']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Complemento'];

                if (strlen($result) > $length) {
                    $app->log->info($registrations->number . " Campo BEN_ENDERECO_COMPLEMENTO Header 2 maior que o permitido o valor foi truncado. Máximo permitido = " . $length);
                }

                $result = substr($result, 0, $length);

                return $result;
            },
            'BEN_ENDERECO_BAIRRO' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_BAIRRO']['field_id'];
                $length = $detahe2['BEN_ENDERECO_BAIRRO']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Bairro'];

                if (strlen($result) > $length) {
                    $app->log->info($registrations->number . " Campo BEN_ENDERECO_BAIRRO Header 2 maior que o permitido o valor foi truncado. Máximo permitido = " . $length);
                }

                $result = substr($result, 0, $length);

                return $result;
            },
            'BEN_ENDERECO_CIDADE' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_CIDADE']['field_id'];
                $length = $detahe2['BEN_ENDERECO_CIDADE']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Municipio'];

                if (strlen($result) > $length) {
                    $app->log->info($registrations->number . " Campo BEN_ENDERECO_CIDADE Header 2 maior que o permitido o valor foi truncado. Máximo permitido = " . $length);
                }

                $result = substr($result, 0, $length);

                return $result;
            },
            'BEN_ENDERECO_CEP' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_CEP']['field_id'];
                $length = $detahe2['BEN_ENDERECO_CEP']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_CEP'];

                if (strlen($result) > $length) {
                    $app->log->info($registrations->number . " Campo BEN_ENDERECO_CEP Header 2 maior que o permitido o valor foi truncado. Máximo permitido = " . $length);
                }

                $result = substr($result, 0, $length);

                return $result;
            },
            'BEN_ENDERECO_ESTADO' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_ESTADO']['field_id'];
                $length = $detahe2['BEN_ENDERECO_CIDADE']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Estado'];

                if (strlen($result) > $length) {
                    $app->log->info($registrations->number . " Campo BEN_ENDERECO_ESTADO Header 2 maior que o permitido o valor foi truncado. Máximo permitido = " . $length);
                }

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
        $field_conta = $default['field_conta'];
        $field_banco = $default['field_banco'];
        foreach ($registrations as $value) {

            if ($this->numberBank($value->$field_banco) == "001") {

                if ($value->$field_conta == "Conta corrente") {
                    $recordsBBCorrente[] = $value;
                } else {
                    $recordsBBPoupanca[] = $value;
                }

            } else {
                $recordsOthers[] = $value;
            }
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
                echo "<p>Problemas na inscrição " . $key . " => " . $value . " </p>";
            }
            unset($_SESSION['problems']);
            //die();
        }

        // header('Content-type: text/utf-8');
        // echo $txt_data;
        // exit();

        /**
         * cria o arquivo no servidor e insere o conteuto da váriavel $txt_data
         */
        $file_name = 'inciso1-cnab240-' . md5(json_encode($txt_data)) . '.txt';

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
        $status = $default['status'];
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
            WHERE e.status = :status AND
            e.opportunity = :opportunity_Id AND
            e.sentTimestamp >=:startDate AND
            e.sentTimestamp <= :finishDate";

            $query = $app->em->createQuery($dql);
            $query->setParameters([
                'opportunity_Id' => $opportunity_id,
                'status' => $status,
                'startDate' => $startDate,
                'finishDate' => $finishDate,
            ]);

            $registrations = $query->getResult();

        } else {
            $dql = "SELECT e FROM MapasCulturais\Entities\Registration e
            WHERE e.status = :status AND
            e.opportunity = :opportunity_Id";

            $query = $app->em->createQuery($dql);
            $query->setParameters([
                'opportunity_Id' => $opportunity_id,
                'status' => $status,
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
                exit();

            },
            'CONTA_DIGITO' => function ($registrations) use ($header1) {
                $result = "";
                $field_id = $header1['CONTA_DIGITO'];
                $value = $this->normalizeString($field_id['default']);
                $result = is_string($value) ? strtoupper($value) : $value;
                return $result;

            },
            'USO_BANCO_20' => '',
            'NOME_EMPRESA' => '',
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
            'USO_BANCO_51' => '',
            'NOME_EMPRESA' => '',
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
            'BEN_AGENCIA' => function ($registrations) use ($detahe1) {
                $field_id = $detahe1['BEN_AGENCIA']['field_id'];
                $age = $registrations->$field_id;

                if (strlen($age) > 4) {
                    $result = substr($age, 0, 4);
                } else {
                    $result = $age;
                }
                return $result;
            },
            'BEN_AGENCIA_DIGITO' => function ($registrations) use ($detahe1) {
                $result = "";
                $field_id = $detahe1['BEN_AGENCIA_DIGITO']['field_id'];
                $dig = $registrations->$field_id;
                if (strlen($dig) > 4) {
                    $result = substr($dig, -1);
                } else {
                    $result = "";
                }

                return is_string($result) ? strtoupper($result) : $result;
            },
            'BEN_CONTA' => function ($registrations) use ($detahe1, $default) {
                $temp = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                $numberBank = $this->numberBank($registrations->$temp);
                $temp = $detahe1['TIPO_CONTA']['field_id'];
                $typeAccount = $registrations->$temp;
                $field_id = $detahe1['BEN_CONTA']['field_id'];
                $account = $registrations->$field_id;

                $result = "";
                if ($numberBank == '001' && $typeAccount == $default['typesAccount']['poupanca']) {

                    if (substr($account, 0, 3) != "510") {
                        $result = "510" . $account;
                    } else {

                        $result = $account;

                    }
                } else {

                    $result = $registrations->$field_id;
                }

                return substr($result, 0, -1); // Remove o ultimo caracter. Intende -se que o ultimo caracter é o DV da conta
            },
            'BEN_CONTA_DIGITO' => function ($registrations) use ($detahe1, $default) {
                $temp = $detahe1['BEN_CODIGO_BANCO']['field_id'];

                $numberBank = $this->numberBank($registrations->$temp);

                $temp = $detahe1['TIPO_CONTA']['field_id'];
                $typeAccount = $registrations->$temp;

                $temp = $detahe1['BEN_CONTA_DIGITO']['field_id'];
                $account = preg_replace('/[^0-9]/i', '', $registrations->$temp);

                $dig = substr($account, -1);

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
                $result = substr($registrations->$field_id, 0, $detahe1['BEN_NOME']['length']);
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
            'VALOR_INTEIRO' => function ($registrations) use ($detahe1) {
                $valor = '100,98';
                $valor = preg_replace('/[^0-9]/i', '', $valor);

                return $valor;
            },
            'USO_BANCO_88' => '',
            'USO_BANCO_89' => '',
            'USO_BANCO_90' => '',
            'CODIGO_FINALIDADE_TED' => function ($registrations) use ($detahe1) {
                $temp = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                $numberBank = $this->numberBank($registrations->$temp);
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
            'BEN_TIPO_DOC' => function ($registrations) use ($detahe2, $default, $field_labelMap) {
                $result = "";
                $type_register = $default['type_register'];
                if (in_array($registrations->category, $type_register['CPF'])) {
                    $result = 1;
                } elseif (in_array($registrations->category, $type_register['CNPJ'])) {
                    $result = 2;
                } else {
                    $_SESSION['problems'][$registrations->number] = "verificar tipo de cadastro (CPF ou CNPJ) na inscrição";
                }
                return $result;
            },
            'BEN_CPF' => function ($registrations) use ($detahe2, $default, $field_labelMap) {
                $type_register = $default['type_register'];

                $result = "";

                if (in_array($registrations->category, $type_register['CPF'])) {
                    $temp = $default['field_CPF'];
                    if (is_array($temp)) {
                        foreach ($temp as $value) {
                            $field_id = array_search(trim($value), $field_labelMap);
                            if ($field_id) {
                                $result = $registrations->$field_id;
                                if (strlen($this->normalizeString($result)) != 11) {
                                    $_SESSION['problems'][$registrations->number] = "CPF Inválido";
                                }
                            }

                        }
                    } else {
                        $field_id = array_search(trim($temp), $field_labelMap);
                        $result = $registrations->$field_id;

                    }
                } elseif (in_array($registrations->category, $type_register['CNPJ'])) {
                    $temp = $default['field_CNPJ'];
                    if (is_array($temp)) {
                        foreach ($temp as $value) {
                            $field_id = array_search(trim($value), $field_labelMap);
                            if ($field_id) {
                                $result = $registrations->$field_id;
                                if (strlen($this->normalizeString($result)) != 14) {
                                    $_SESSION['problems'][$registrations->number] = "CNPJ inválido";
                                }
                            }
                        }
                    } else {
                        $field_id = array_search(trim($temp), $field_labelMap);
                        $result = $registrations->$field_id;

                    }
                } else {
                    $_SESSION['problems'][$registrations->number] = "verificar tipo de cadastro (CPF ou CNPJ) na inscrição.";
                }

                return $this->normalizeString($result);
            },
            'BEN_ENDERECO_LOGRADOURO' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_LOGRADOURO']['field_id'];
                $length = $detahe2['BEN_ENDERECO_LOGRADOURO']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Nome_Logradouro'];

                if (strlen($result) > $length) {
                    $app->log->info($registrations->number . " Campo BEN_ENDERECO_LOGRADOURO Header 2 maior que o permitido o valor foi truncado. Máximo permitido = " . $length);
                }
                $result = substr($result, 0, $length);

                return $result;

            },
            'BEN_ENDERECO_NUMERO' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_NUMERO']['field_id'];
                $length = $detahe2['BEN_ENDERECO_NUMERO']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Num'];

                if (strlen($result) > $length) {
                    $app->log->info($registrations->number . " Campo BEN_ENDERECO_NUMERO Header 2 maior que o permitido o valor foi truncado. Máximo permitido = " . $length);
                }

                $result = substr($result, 0, $length);

                return $result;
            },
            'BEN_ENDERECO_COMPLEMENTO' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_COMPLEMENTO']['field_id'];
                $length = $detahe2['BEN_ENDERECO_COMPLEMENTO']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Complemento'];

                if (strlen($result) > $length) {
                    $app->log->info($registrations->number . " Campo BEN_ENDERECO_COMPLEMENTO Header 2 maior que o permitido o valor foi truncado. Máximo permitido = " . $length);
                }

                $result = substr($result, 0, $length);

                return $result;
            },
            'BEN_ENDERECO_BAIRRO' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_BAIRRO']['field_id'];
                $length = $detahe2['BEN_ENDERECO_BAIRRO']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Bairro'];

                if (strlen($result) > $length) {
                    $app->log->info($registrations->number . " Campo BEN_ENDERECO_BAIRRO Header 2 maior que o permitido o valor foi truncado. Máximo permitido = " . $length);
                }

                $result = substr($result, 0, $length);

                return $result;
            },
            'BEN_ENDERECO_CIDADE' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_CIDADE']['field_id'];
                $length = $detahe2['BEN_ENDERECO_CIDADE']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Municipio'];

                if (strlen($result) > $length) {
                    $app->log->info($registrations->number . " Campo BEN_ENDERECO_CIDADE Header 2 maior que o permitido o valor foi truncado. Máximo permitido = " . $length);
                }

                $result = substr($result, 0, $length);

                return $result;
            },
            'BEN_ENDERECO_CEP' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_CEP']['field_id'];
                $length = $detahe2['BEN_ENDERECO_CEP']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_CEP'];

                if (strlen($result) > $length) {
                    $app->log->info($registrations->number . " Campo BEN_ENDERECO_CEP Header 2 maior que o permitido o valor foi truncado. Máximo permitido = " . $length);
                }

                $result = substr($result, 0, $length);
                return $result;
            },
            'BEN_ENDERECO_ESTADO' => function ($registrations) use ($detahe2, $app) {
                $field_id = $detahe2['BEN_ENDERECO_ESTADO']['field_id'];
                $length = $detahe2['BEN_ENDERECO_ESTADO']['length'];
                $data = $registrations->$field_id;
                $result = $data['En_Estado'];

                if (strlen($result) > $length) {
                    $app->log->info($registrations->number . " Campo BEN_ENDERECO_ESTADO Header 2 maior que o permitido o valor foi truncado. Máximo permitido = " . $length);
                }

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
        $field_conta = array_search(trim($default['field_conta']), $field_labelMap);
        $field_banco = array_search(trim($default['field_banco']), $field_labelMap);
        foreach ($registrations as $value) {
            if ($this->numberBank($value->$field_banco) == "001") {

                if ($value->$field_conta == "Conta corrente") {
                    $recordsBBCorrente[] = $value;
                } else {
                    $recordsBBPoupanca[] = $value;
                }

            } else {
                $recordsOthers[] = $value;
            }
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
                echo "<p>Problemas na inscrição " . $key . " => " . $value . " </p>";
            }
            unset($_SESSION['problems']);
            die();
        }

        // header('Content-type: text/utf-8');
        // echo $txt_data;
        // exit();

        /**
         * cria o arquivo no servidor e insere o conteuto da váriavel $txt_data
         */
        $file_name = 'inciso2-cnab240-' . md5(json_encode($txt_data)) . '.txt';

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

    public function ALL_exportMCI460()
    {
        $this->requireAuthentication();
        $app = App::i();
        if (!empty($this->data)) {
            // oportunidades
            if (isset($this->data["opportunity"])) {
                $opportunityIDs = explode(",", $this->data["op"]);
                foreach ($opportunityIDs as $oID) {
                    if (!is_numeric($oID)) {
                        throw new Exception("Oportunidade(s) inválida(s)");
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
        $config = $this->config["config-mci460"];
        if (!isset($config["condition"])) {
            throw new Exception("Configuração inválida: \"condition\" não configurada.");
        }
        $newline = "\r\n";
        set_time_limit(0);
        header("Content-type: text/utf-8");
        flush();
        // inicializa contadores
        $nLines = 1;
        $nClients = 0;
        // gera o header
        echo($this->mci460Header($config) . $newline);
        // percorre as oportunidades
        foreach ($opportunities as $opportunity) {
            // pega inscrições via DQL seguindo recomendações do Doctrine para grandes volumes
            /**
             * TODO: selecionar corretamente as inscrições por homologação + avaliação DataPrev
             */
            $dql = "SELECT e FROM MapasCulturais\Entities\Registration e
                             WHERE e.status IN (1, 10) AND e.opportunity=:oppId";
            $query = $app->em->createQuery($dql);
            $registrations = $query->iterate(["oppId" => $opportunity->id]);
            /**
             * Mapeamento de fielsds_id pelo label do campo
             */
            $this->registerRegistrationMetadata($opportunity);
            // processa inscrições
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
                echo(implode($newline, $details) . $newline);
            }
        }
        ++$nLines;
        echo($this->mci460Trailer($config, [
            "totalClientes" => $nClients,
            "totalRegistros" => $nLines,
        ]) . $newline);
        return;
    }

public function ALL_addressReport()
{
    $this->requireAuthentication();
    $app = App::i();
    // pega oportunidades via ORM
    $opportunityIDs = [1];
    if (isset($opportunityIDs)) {
        $opportunities = $app->repo("Opportunity")->findBy(["id" => $opportunityIDs]);
    } else {
        $opportunities = $app->repo("Opportunity")->findAll();
    }
    set_time_limit(0);
    header("Content-Type: application/csv");
    header("Pragma: no-cache");
    flush();
    $header = ["Inscrição", "Nome", "Logradouro", "Número", "Complemento",
               "Bairro", "Município", "Estado", "CEP"];
    $report = [];
    $config = $this->config["config-mci460"];
    $address = $config["fieldMap"]["endereco"];
    foreach ($opportunities as $opportunity) {
        $part = ($opportunity->id == $this->config["inciso1_opportunity_id"]) ? 1 :
                (in_array($opportunity->id, $this->config["inciso2_opportunity_ids"]) ? 2 : 3);
        if ($part != 1) { continue; }
        /**
         * TODO: selecionar corretamente as inscrições por homologação + avaliação DataPrev
         */
        // pega inscrições via DQL seguindo recomendações do Doctrine para grandes volumes
        $dql = "SELECT e FROM MapasCulturais\Entities\Registration e
                         WHERE e.status IN (1, 10) AND e.opportunity=:oppId";
        $query = $app->em->createQuery($dql);
        $registrations = $query->iterate(["oppId" => $opportunity->id]);
        /**
         * Mapeamento de fielsds_id pelo label do campo
         */
        $this->registerRegistrationMetadata($opportunity);
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
    }
    $csv = Writer::createFromString();
    $csv->insertOne($header);
    foreach ($report as $line) {
        $csv->insertOne($line);
    }
    echo($csv->getContent());
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
                //["target" => "type", "matching" => ["TIPO DE CONTA BANCÁRIA:", "TIPO DE CONTA BANCÁRIA"]],
                ["target" => "bank", "matching" => ["BANCO:"]],
                ["target" => "branch", "matching" => ["AGÊNCIA", "Número de agência:"]],
                ["target" => "account", "matching" => ["NÚMERO DA CONTA:", "Número de conta:"]],
                ["target" => "operation", "matching" => ["NÚMERO DA OPERAÇÃO SE HOUVER", "Número de operação (se houver):"]],
                ["target" => "payment", "matching" => ["FORMA PARA RECEBIMENTO DO BENEFÍCIO:"]],
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
                $bank = $interestFields["bank"];
                $bank = $registration->$bank;
                $bankNumber = $this->numberBank($bank);
                $branch = $interestFields["branch"];
                $branch = $registration->$branch;
                $account = $interestFields["account"];
                $account = $registration->$account;
                $operation = $interestFields["operation"];
                $operation = $registration->$operation;
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

    /**
     * Placeholder para o número de seqüência dos arquivos de remessa.
     */
    public function sequenceNumber($type)
    {
        $n = 0;
        switch ($type) {
            case "cnab240": break;
            case "mci460": break;
            default: break;
        }
        return $n;
    }

    //###################################################################################################################################

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
     * Encontra os campos especificados pelos parâmetros. Exemplo:
     * $this->findFields([
     *      [
     *          "target" => "type",
     *          "matching" => [
     *              "TIPO DE CONTA BANCÁRIA:",
     *              "TIPO DE CONTA BANCÁRIA"
     *          ]
     *      ]
     * ], $opportunity->registrationFieldConfigurations);
     * Retornará um dicionário ["type" => "<field_id>"] para o primeiro campo de
     * $opportunity->registrationFieldConfigurations cujo nome for algum dos
     * nomes passados em "matching".
     */
    private function findFields($fieldSpecs, $fieldConfigs)
    {
        $fields = [];
        foreach ($fieldConfigs as $field) {
            $title = trim($field->title);
            foreach ($fieldSpecs as $spec) {
                if (in_array($title, $spec["matching"])) {
                    $fields[$spec["target"]] = "field_" . $field->id;
                    break;
                }
            }
            if (sizeof($fields) > sizeof($fieldSpecs)) {
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
                    if (strlen($data($register)) > $value['length']) {
                        $app->log->info($register . " Esta com campo " . $key . " Maior que o permitido. Máximo permitido " . $value['length']);

                    }

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
        foreach ($config["details"] as $detail) {
            if (isset($detail["condition"])) {
                if (!$this->mci460Thunk2($detail["condition"], $config,
                                         $registration)) {
                    continue;
                }
            }
            $line = "";
            foreach ($detail["fields"] as $field) {
                if (!isset($field["default"])) {
                    if ($field["type"] === "meta") {
                        $line .= $this->mci460MetaField($config, $field, $registration);
                        continue;
                    }
                    $fieldName = $field["name"];
                    if (!isset($config["fieldMap"][$fieldName])) {
                        $field["default"] = $extraData[$fieldName];
                    } else {
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
