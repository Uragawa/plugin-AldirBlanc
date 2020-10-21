<?php
/**
 * Via Credi 	9999 	99999999999-D
 * Neon 	9999 	9999999-D
 * Banestes 	9999 	99999999-D
 * Unicred 	9999 	99999999-D
 * Money Plus 	9 	99999999-D
 * Mercantil do Brasil 	9999 	99999999-D
 * JP Morgan 	9999 	99999999999-D
 * Gerencianet Pagamentos do Brasil 	9999 	99999999-D
 * Banco Topazio 	9999 	99999-D
 * Uniprime 	9999 	99999-D
 * Banco Stone 	9999 	999999-D
 * Banco Daycoval 	9999 	999999-D
 * Rendimento 	9999-D 	9999999999
*/
return [
	"byName" => [
		"bco do brasil sa" => "001",
		"banco do brasil sa" => "001",
		"bco da amazonia sa" => "003",
		"bco do nordeste do brasil sa" => "004",
		"bco banestes sa" => "021",
		"bco santander brasil sa" => "033",
		"bco do est do pa sa" => "037",
		"banpara" => "037",
		"banco do estado do para" => "037",
		"bco do estado do rs sa" => "041",
		"bco do est de se sa" => "047",
		"brb  bco de brasilia sa" => "070",
		"banco inter" => "077",
		"bco da china brasil sa" => "083",
		"caixa economica federal" => "104",
		"banco btg pactual sa" => "208",
		"banco original" => "212",
		"bco bradesco sa" => "237",
		"bco bmg sa" => "318",
		"itau unibanco sa" => "341",
		"bco safra sa" => "422",
		"banco pan" => "623",
		"br partners bi" => "666",
		"nu pagamentos sa" => "260",
		"bco votorantim sa" => "655",
		"mercado pago" => "323",
		"credisis central de cooperativas de credito ltda" => "097",
		"bco bradesco financ sa" => "394",
		"pagseguro" => "290",
		"bs2 dtvm sa" => "292",
		"bco bradesco berj sa" => "122",
		"amazonia cc ltda" => "313",
		"bco c6 sa" => "336",
		"bco itau bba sa" => "184",
		"bco caixa geral brasil sa" => "473",
		"bco pine sa" => "643",
		"fram capital dtvm sa" => "331",
		"bco modal sa" => "746",
		"bco cooperativo sicredi sa" => "748",
		"rb capital investimentos dtvm ltda" => "283",
		"bancoob" => "756",
		"banco digio" => "335",
		"itau unibanco holding sa" => "652",
	],
	"byNumber" => [
		"001" => [
			"name" => "Banco do Brasil S.A.",
			"branch" => [
				"regex" => "/^\d{1,4}\s*-?\s*[0-9xX]$/",
				"length" => 4,
				"digit" => ["map" => ["X" => "0", "x" => "0"]]
			], //"9999-D",
			"account" => [
				"regex" => "/^\d{1,8}\s*-?\s*[0-9xX]$/i",
				"length" => 8,
				"digit" => ["map" => ["X" => "0", "x" => "0"]]
			], //"99999999-D"
		],
		"003" => [ // from netbanking
			"name" => "Banco da Amazônia S.A.",
			"branch" => [
				"length" => 3
			],
			"account" => [
				"length" => 6,
				"digit" => true
			]
		],
		//"004" => ["name" => "bco do nordeste do brasil sa"],
		//"021" => ["name" => "bco banestes sa"],
		"033" => [
			"name" => "Banco Santander Brasil S.A.",
			"branch" => [
				"regex" => "/^\d{1,4}$/",
				"length" => 4
			], //"9999",
			"account" => [
				"regex" => "/^\d{1,8}\s*-?\s*[0-9]$/",
				"length" => 8,
				"digit" => true
			], //"99999999-D"
		],
		"037" => [ // from netbanking
			"name" => "Banco do Estado do Pará S.A.",
			"branch" => [
				"length" => 4
			],
			"account" => [
				"length" => 9,
				"digit" => true
			]
		],
		"041" => [
			"name" => "Banco do Estado do Rio Grande do Sul S.A.",
			"branch" => "/^\d{1,4}$/", //"9999",
			"account" => "/^\d{1,9}\s*-?\s*[0-9]$/", //"999999999-D"
		],
		//"047" => ["name" => "Banco do Estado de Sergipe S.A."],
		"070" => [
			"name" => "BRB - Banco de Brasília S.A.",
			"branch" => "/^\d{1,4}$/", //"9999",
			"account" => "/^\d{1,9}\s*-?\s*[0-9]$/", //"999999999-D"
		],
		"077" => [
			"name" => "Banco Inter",
			"branch" => "/^\d{1,4}$/", //"9999",
			"account" => "/^\d{1,9}\s*-?\s*[0-9]$/", //"999999999-D"
		],
		//"083" => ["name" => "bco da china brasil sa"],
		"104" => [
			"name" => "Caixa Econômica Federal",
			"branch" => [
				"regex" => "/^\d{1,4}$/",
				"length" => 4
			], //"9999",
			"account" => [
				"regex" => "/^(((001|002|003|013|022|023)?\d{8})|\d{1,8})\s*-?\s*[0-9]$/",
				"length" => 8,
				"digit" => true,
				"prefix" => [
					"totalLength" => 11,
					"values" => ["001", "002", "003", "013", "022", "023"]
				]
			], //"XXX99999999-D", // XXX is operation
			"operation" => ["001", "002", "003", "013", "022", "023"]
		],
		//"208" => ["name" => "banco btg pactual sa"],
		"212" => [
			"name" => "Banco Original",
			"branch" => "/^\d{1,4}$/", //"9999",
			"account" => "/^\d{1,7}\s*-?\s*[0-9]$/", //"9999999-D"
		],
		"237" => [
			"name" => "Banco Bradesco S.A.",
			"branch" => "/^\d{1,4}\s*-?\s*[0-9]$/", //"9999-D",
			"account" => "/^\d{1,7}\s*-?\s*[0-9]$/", //"9999999-D"
		],
		"318" => [ // from phone support
			"name" => "Banco Bmg S.A.",
			"branch" => [
				"length" => 4
			],
			"account" => [
				"length" => 4,
				"digit" => true
			]
		],
		"341" => [
			"name" => "Itaú Unibanco S.A.",
			"branch" => "/^\d{1,4}$/", //"9999",
			"account" => "/^\d{1,5}\s*-?\s*[0-9]$/", //"99999-D"
		],
		"422" => [
			"name" => "Banco Safra S.A.",
			"branch" => "/^\d{1,4}$/", //"9999",
			"account" => "/^\d{1,8}\s*-?\s*[0-9]$/", //"99999999-D"
		],
		//"623" => [ /* NEED THIS RULE */ "name" => "Banco PAN S.A."],
		//"666" => ["name" => "BR Partners BI"],
		"260" => [
			"name" => "Nu Pagamentos S.A.",
			"branch" => "/^\d{1,4}$/", //"9999",
			"account" => "/^\d{1,10}\s*-?\s*[0-9]$/", //"9999999999-D"
		],
		"655" => [
			"name" => "Banco Votorantim S.A.",
			"branch" => "/^\d{1,4}$/", //"9999",
			"account" => "/^\d{1,7}\s*-?\s*[0-9]$/", //"9999999-D"
		],
		//"323" => [ /* NEED THIS RULE */ "name" => "mercado pago"],
		"097" => [ // from netbanking
			"name" => "CrediSIS - Central de Cooperativas de Crédito Ltda.",
			"branch" => [
				"length" => 4
			],
			"account" => [
				"length" => 7,
				"digit" => true
			]
		],
		//"394" => [ /* NEED THIS RULE */ "name" => "Banco Bradesco Financiamentos S.A."],
		"290" => [
			"name" => "PagSeguro",
			"branch" => "/^\d{1,4}$/", //"9999",
			"account" => "/^\d{1,8}\s*-?\s*[0-9]$/", //"99999999-D"
		],
		"292" => [
			"name" => "BS2 DTVM S.A.",
			"branch" => "/^\d{1,4}$/", //"9999",
			"account" => "/^\d{1,6}\s*-?\s*[0-9]$/", //"999999-D"
		],
		//"122" => [ /* NEED THIS RULE */ "name" => "Banco Bradesco BERJ S.A."],
		//"313" => ["name" => "amazonia cc ltda"],
		"336" => [
			"name" => "Banco C6 S.A.",
			"branch" => "/^\d{1,4}$/", //"9999",
			"account" => "/^\d{1,7}\s*-?\s*[0-9]$/", //"9999999-D"
		],
		"184" => [ //from netbanking
			"name" => "Banco Itaú BBA S.A.",
			"branch" => [
				"length" => 4
			],
			"account" => [
				"length" => 5,
				"digit" => true
			]
		],
		//"473" => ["name" => /* NEED THIS RULE */ "Banco Caixa Geral Brasil S.A."],
		//"643" => ["name" => "bco pine sa"],
		//"331" => ["name" => "fram capital dtvm sa"],
		"746" => [
			"name" => "Banco Modal S.A.",
			"branch" => "/^\d{1,4}$/", //"9999",
			"account" => "/^\d{1,9}\s*-?\s*[0-9]$/", //"999999999-D"
		],
		"748" => [
			"name" => "bco cooperativo sicredi sa",
			"branch" => "/^\d{1,4}$/", //"9999",
			"account" => "/^\d{1,6}[0-9]$/", //"999999D"
		],
		//"283" => ["name" => "rb capital investimentos dtvm ltda"],
		"756" => [
			"name" => "bancoob",
			"branch" => "/^\d{1,4}$/", //"9999",
			"account" => "/^\d{1,9}\s*-?\s*[0-9]$/", //"999999999-D"
		],
		"335" => [ // from website
			"name" => "digio",
			"branch" => "/^\d{1,4}$/", //"9999",
			"account" => "/^\d{1,7}\s*-?\s*[0-9]$/", //"9999999-D"
		],
		//"652" => [ /* NEED THIS RULE */ */ "name" => "Itaú Unibanco Holding S.A."],
	]
];
?>