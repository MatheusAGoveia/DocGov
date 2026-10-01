<?php

/** Consulta o diretório sem autenticar como as pessoas que serão importadas. */
interface DirectoryImportGateway
{
    /** Uma resposta por identificador, na mesma ordem; nenhuma escrita no banco. */
    public function lookupDirectoryUsers(array $identifiers, string $domainKey): array;

    /** Cria somente contas novas; preserva bloqueios, papéis e vínculos existentes. */
    public function provisionDirectoryUser(array $entry, string $domainKey): array;
}
