<?php
// database/migrate_legacy_data.php - Script de Migração de Dados Legados para PostgreSQL
require_once __DIR__ . '/../config/db.php';

echo "========================================================\n";
echo "    MIGRAÇÃO DE DADOS LEGADOS -> POSTGRESQL (DocGov)     \n";
echo "========================================================\n";

try {
    // 1. Usuários Principais
    $usersSeed = [
        ['Samuel Oliveira', 'samuel', 'samuel@prefeitura.gov.br', 'reader'],
        ['Maria Santos', 'maria', 'maria@prefeitura.gov.br', 'reader'],
        ['João Silva', 'joao', 'joao@prefeitura.gov.br', 'reader']
    ];

    $hashDefault = password_hash('user123', PASSWORD_DEFAULT);
    $stmtUser = $pdo->prepare("
        INSERT INTO users (name, username, email, password_hash, role, active)
        VALUES (:name, :username, :email, :password_hash, :role, TRUE)
        ON CONFLICT (username) DO UPDATE SET role = EXCLUDED.role
    ");

    foreach ($usersSeed as $u) {
        $stmtUser->execute([
            ':name' => $u[0],
            ':username' => $u[1],
            ':email' => $u[2],
            ':password_hash' => $hashDefault,
            ':role' => $u[3]
        ]);
    }
    echo "✓ Usuários migrados/verificados.\n";

    // 2. Categorias Iniciais
    $categoriesMap = [];
    $categoriesSeed = [
        ['Recursos Humanos', 'recursos-humanos', 'Políticas internas, licenças e gestão de servidores públicos.'],
        ['Saúde & Vigilância', 'saude-vigilancia', 'Atendimentos SUS, vacinação e decretos de saúde.'],
        ['Educação Municipal', 'educacao-municipal', 'Rede de ensino, diretrizes escolares e matrículas.'],
        ['Finanças & Orçamento', 'financas-orcamento', 'IPTU, taxas, balancetes e portais de transparência.']
    ];

    $stmtCat = $pdo->prepare("
        INSERT INTO categories (name, slug, description, active)
        VALUES (:name, :slug, :description, TRUE)
        ON CONFLICT (slug) DO UPDATE SET name = EXCLUDED.name
        RETURNING id, slug;
    ");

    foreach ($categoriesSeed as $c) {
        $stmtCat->execute([':name' => $c[0], ':slug' => $c[1], ':description' => $c[2]]);
        $row = $stmtCat->fetch();
        $categoriesMap[$c[1]] = (int)$row['id'];
    }
    echo "✓ Categorias migradas/verificadas.\n";

    // 3. Subcategorias Iniciais
    $subcategoriesMap = [];
    $subcategoriesSeed = [
        ['recursos-humanos', 'Férias e Licenças', 'ferias-e-licencas', 'Solicitações e agendamentos de férias.'],
        ['recursos-humanos', 'Benefícios', 'beneficios', 'Vale alimentação, plano e adicionais.'],
        ['recursos-humanos', 'Folha de Pagamento', 'folha-de-pagamento', 'Holerites e rendimentos.'],
        ['saude-vigilancia', 'Atendimento SUS', 'atendimento-sus', 'Horários de UBS e agendamentos.'],
        ['educacao-municipal', 'Matrículas Escolares', 'matriculas-escolares', 'Prazos de inscrição creches e escolas.'],
        ['financas-orcamento', 'Impostos Municipais', 'impostos-municipais', 'Tributos e 2ª via IPTU.']
    ];

    $stmtSub = $pdo->prepare("
        INSERT INTO subcategories (category_id, name, slug, description, active)
        VALUES (:category_id, :name, :slug, :description, TRUE)
        ON CONFLICT (category_id, slug) DO UPDATE SET name = EXCLUDED.name
        RETURNING id, slug;
    ");

    foreach ($subcategoriesSeed as $sc) {
        $catId = $categoriesMap[$sc[0]];
        $stmtSub->execute([':category_id' => $catId, ':name' => $sc[1], ':slug' => $sc[2], ':description' => $sc[3]]);
        $row = $stmtSub->fetch();
        $subcategoriesMap[$sc[2]] = (int)$row['id'];
    }
    echo "✓ Subcategorias migradas/verificadas.\n";

    // 4. Assuntos Iniciais
    $subjectsMap = [];
    $subjectsSeed = [
        ['ferias-e-licencas', 'Solicitação de Férias', 'solicitacao-de-ferias', 'Formulário padrão de férias.'],
        ['ferias-e-licencas', 'Licença Médica', 'licenca-medica', 'Atestados e perícia oficial.'],
        ['beneficios', 'Vale Alimentação', 'vale-alimentacao', 'Datas de crédito e regras.'],
        ['folha-de-pagamento', 'Holerite Online', 'holerite-online', 'Guia de contra-cheque.'],
        ['atendimento-sus', 'Agendamento UBS', 'agendamento-ubs', 'Marcação de consultas.'],
        ['matriculas-escolares', 'Rede Infantil', 'rede-infantil', 'Matrículas pré-escola.'],
        ['impostos-municipais', 'IPTU 2026', 'iptu-2026', 'Emissão de carnê.']
    ];

    $stmtAss = $pdo->prepare("
        INSERT INTO subjects (subcategory_id, name, slug, description, active)
        VALUES (:subcategory_id, :name, :slug, :description, TRUE)
        ON CONFLICT (subcategory_id, slug) DO UPDATE SET name = EXCLUDED.name
        RETURNING id, slug;
    ");

    foreach ($subjectsSeed as $s) {
        $subId = $subcategoriesMap[$s[0]];
        $stmtAss->execute([':subcategory_id' => $subId, ':name' => $s[1], ':slug' => $s[2], ':description' => $s[3]]);
        $row = $stmtAss->fetch();
        $subjectsMap[$s[2]] = (int)$row['id'];
    }
    echo "✓ Assuntos migrados/verificados.\n";

    // 5. Documentos de Exemplo
    $docsSeed = [
        ['solicitacao-de-ferias', 1, 'Requerimento Padrão de Férias', 'requerimento-padrao-de-ferias', 'Formulário oficial para agendamento de férias.', 'text', 'published', '<h3>Solicitação de Férias</h3><p>Protocolar com no mínimo 30 dias de antecedência junto ao setor de Recursos Humanos.</p>', NULL],
        ['licenca-medica', 1, 'Guia de Atestado e Perícia Médica', 'guia-de-atestado-e-pericia-medica', 'Procedimentos para perícia médica oficial.', 'text', 'published', '<h3>Licenças Médicas</h3><p>Atestados superiores a 3 dias dependem de homologação presencial.</p>', NULL],
        ['vale-alimentacao', 1, 'Regras do Vale Alimentação', 'regras-do-vale-alimentacao', 'Valores e datas de crédito do vale alimentício.', 'text', 'published', '<h3>Vale Alimentação</h3><p>Crédito disponibilizado todo 1º dia útil do mês.</p>', NULL],
        ['holerite-online', 1, 'Guia de Emissão de Holerite Online', 'guia-de-emissao-de-holerite-online', 'Como emitir o holerite mensal.', 'text', 'published', '<h3>Holerite Online</h3><p>Acesse com sua matrícula e senha cadastrada.</p>', NULL],
        ['agendamento-ubs', 2, 'Protocolo de Agendamento nas UBS', 'protocolo-de-agendamento-nas-ubs', 'Horários para marcação de consultas.', 'text', 'published', '<h3>Agendamento UBS</h3><p>Presencial ou via aplicativo de saúde da Prefeitura.</p>', NULL],
        ['rede-infantil', 2, 'Calendário de Matrículas 2026', 'calendario-de-matriculas-2026', 'Inscrições para creches municipais.', 'text', 'published', '<h3>Matrículas 2026</h3><p>Consulte os prazos e documentações no edital publicado no Diário Oficial.</p>', NULL],
        ['iptu-2026', 1, 'Guia de Pagamento IPTU 2026', 'guia-de-pagamento-iptu-2026', 'Instruções para emissão de 2ª via.', 'link', 'published', NULL, 'https://portaldatransparencia.gov.br']
    ];

    $stmtDoc = $pdo->prepare("
        INSERT INTO documents (
            subject_id, created_by, title, slug, description, content_type, status, published_at, text_content, external_url
        ) VALUES (
            :subject_id, :created_by, :title, :slug, :description, :content_type, :status, CURRENT_TIMESTAMP, :text_content, :external_url
        ) ON CONFLICT (subject_id, slug) DO UPDATE SET title = EXCLUDED.title
    ");

    foreach ($docsSeed as $d) {
        $subjId = $subjectsMap[$d[0]];
        $stmtDoc->execute([
            ':subject_id' => $subjId,
            ':created_by' => $d[1],
            ':title' => $d[2],
            ':slug' => $d[3],
            ':description' => $d[4],
            ':content_type' => $d[5],
            ':status' => $d[6],
            ':text_content' => $d[7],
            ':external_url' => $d[8]
        ]);
    }
    echo "✓ Documentos migrados/verificados.\n";

    echo "========================================================\n";
    echo "         MIGRAÇÃO CONCLUÍDA COM SUCESSO!                \n";
    echo "========================================================\n";

} catch (Exception $e) {
    echo "❌ Erro durante a migração: " . $e->getMessage() . "\n";
}
