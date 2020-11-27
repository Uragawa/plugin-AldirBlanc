## [Unreleased]

## [v2.3.0] - 2020-10-27

- Corrige bug na exportação da planilha de endereços (Ref. [#156](https://github.com/mapasculturais/plugin-AldirBlanc/issues/156))
- Corrige bug na exportação do PPG100 e implementa novo número de protocolo (Ref. [#150](https://github.com/mapasculturais/plugin-AldirBlanc/issues/150))
- Insere função nos exportadores CNAB240 e genérico, para exportar uma lista de inscrições passadas pelo usuário (Ref. [#157](https://github.com/mapasculturais/plugin-AldirBlanc/issues/157))
- Corrige forma de capturar DV da conta em casos de contas digital BB (Ref. [#158](https://github.com/mapasculturais/plugin-AldirBlanc/issues/158))
- Refatora exportador CNAB240 para o retorno do DV da conta nao ignorar strings EX: DV = X #158 (Ref. [#158](https://github.com/mapasculturais/plugin-AldirBlanc/issues/158))
- Corrige exportador CNAB240 para sempre pegar o ultimo caracter no DV caso ele tenha 2 EX. 57 irá retornar 7 (Ref. [#158](https://github.com/mapasculturais/plugin-AldirBlanc/issues/158))
- Corrige nome do input no formulário do CNAB240 (Ref. [#160](https://github.com/mapasculturais/plugin-AldirBlanc/issues/160))
- Adiciona possibilidade mensagem no lugar do botão de enviar inscrição quando desabilitado através da configuração 'mensagens_envio_desabilitado'  (Ref. [#159](https://github.com/mapasculturais/plugin-AldirBlanc/issues/159))
- Adiciona possibilidade de impedir envios de inscrição de um array de oportunidades através da configuração 'oportunidades_desabilitar_envio' (Ref. [#159](https://github.com/mapasculturais/plugin-AldirBlanc/issues/159))

## [v2.2.0] - 2020-10-25

- Padronização dos exportadores para uso dos sistemas (de-para) via CSV (Ref. [#150](https://github.com/mapasculturais/plugin-AldirBlanc/issues/150))
- Opção de exportar por Data de pagamento nos exportadores genéricos, CNAB240 e PPG100 (Ref. [#150](https://github.com/mapasculturais/plugin-AldirBlanc/issues/150))
- Opção para exportar inscrições que já tenha pagamento cadastrados e ainda não foram enviadas para pagamento nos exportadores genéricos, CNAB240 e PPG100 (Ref. [#150](https://github.com/mapasculturais/plugin-AldirBlanc/issues/150))
- Opção para exportar todas as inscrições que tenham pagamento cadastrados independentemente do status nos exportadores genéricos, CNAB240 e PPG100 (Ref. [#150](https://github.com/mapasculturais/plugin-AldirBlanc/issues/150))
- Fazer com que o status do pagamento mude para 3 ao exportar pagamentos ainda não exportados nos exportadores genéricos, CNAB240 e PPG100 (Ref. [#150](https://github.com/mapasculturais/plugin-AldirBlanc/issues/150))
- Adiciona coluna na exportação das inscrições informando se foi feita por mediador (Ref. [#35](https://git.hacklab.com.br/mapas/MapasBR/-/issues/35))
- Corrige exportador CNAB240 para não ignorar casas decimais no somatório dos treillers do lote (Ref. [#151](https://github.com/mapasculturais/plugin-AldirBlanc/issues/151))

## [v2.1.0] - 2020-10-23

- Adiciona quebra de linha nas avaliações nas mensagens de status (Ref. [#12](https://git.hacklab.com.br/mapas/mapas-es/-/issues/12))
- corrige fonte do número de seqüência dos arquivos
- Adiciona configuracao para definir mensagem de reprocessamento do Dataprev
- prepara plugin validador para o inciso 3

## [v2.0.0] - 2020-10-23

## [v1.0.0] - 2020-10-02