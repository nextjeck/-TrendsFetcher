<?php
session_start(); // Démarrage de la session


// Gérer la soumission de la clé API
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apiKey'])) {
    $apiKey = trim($_POST['apiKey']); // Nettoyer la clé entrée
    if (!empty($apiKey)) {
        $_SESSION['apiKey'] = $apiKey; // Stocker la clé API dans la session
        echo "<p class='success'>Clé API enregistrée avec succès.</p>";
    } else {
        echo "<p class='error'>Veuillez entrer une clé API valide.</p>";
    }
}

// Vérifier si la clé API est définie dans la session
if (!isset($_SESSION['apiKey']) || empty($_SESSION['apiKey'])) {
    echo '<p class="error">Erreur : Vous devez entrer une clé API OpenAI pour continuer.</p>';
    // Formulaire pour entrer la clé API
    echo '<form method="POST" action="">
            <label for="apiKey">Entrez votre clé API OpenAI :</label>
            <input type="password" id="apiKey" name="apiKey" placeholder="sk-..." value="">
            <button type="submit" class="button">Enregistrer</button>
          </form>';
    exit; // Arrêter le script si la clé API n'est pas fournie
}

// URL du flux RSS
$geo = isset($_GET['geo']) ? $_GET['geo'] : 'FR'; // Région par défaut : France
$rssUrl = "https://trends.google.fr/trending/rss?geo=$geo";

// Charger le flux RSS avec mise en cache
$cacheFile = "rss_cache_$geo.json";
$cacheTime = 3600; // Cache de 1 heure

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
    $rssContent = file_get_contents($cacheFile);
} else {
    $rssContent = file_get_contents($rssUrl);
    if ($rssContent !== false) {
        file_put_contents($cacheFile, $rssContent);
    }
}

// Vérifier si le flux RSS a été correctement chargé
if ($rssContent === false || empty($rssContent)) {
    die("Erreur : Impossible de charger ou de lire le flux RSS.");
}



// Convertir le contenu RSS en un objet SimpleXML avec gestion des namespaces
$rssData = simplexml_load_string($rssContent, null, LIBXML_NOCDATA);
if ($rssData === false) {
    die("Erreur : Le contenu RSS est invalide ou ne peut pas être analysé.");
}

// Récupérer les namespaces
$namespaces = $rssData->getNamespaces(true);

// Initialiser le tableau des tendances
$trends = [];
$growthThreshold = 50; // Seuil de croissance pour détecter une tendance émergente

function calculateGrowthRate($title, $currentTraffic) {
    global $logFile;
    $logData = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : [];
    foreach ($logData as $log) {
        foreach ($log['trends'] as $oldTrend) {
            if ($oldTrend['title'] === $title) {
                $previousTraffic = intval(str_replace('+', '', $oldTrend['traffic']) ?: 0);
                $currentTraffic = intval(str_replace('+', '', $currentTraffic) ?: 0);
                if ($previousTraffic > 0) {
                    return round((($currentTraffic - $previousTraffic) / $previousTraffic) * 100, 2);
                }
            }
        }
    }
    return 0; // Pas de données historiques disponibles
}

// Définir l'intention de recherche
function determineSearchIntent($title) {
    // Exemple simple de détection d'intention
    if (stripos($title, 'acheter') !== false || stripos($title, 'prix') !== false) {
        return 'Transactionnelle';
    } elseif (stripos($title, 'comment') !== false || stripos($title, 'pourquoi') !== false) {
        return 'Informationnelle';
    } elseif (stripos($title, 'meilleur') !== false || stripos($title, 'comparatif') !== false) {
        return 'Commerciale';
    } else {
        return 'Navigationnelle';
    }
}

// Récupérer les tendances du flux
if (isset($rssData->channel->item)) {
    foreach ($rssData->channel->item as $item) {
        $title = (string)$item->title;
        $traffic = isset($namespaces['ht']) ? (string)$item->children($namespaces['ht'])->approx_traffic : "N/A";
        $growthRate = calculateGrowthRate($title, $traffic);
        $intent = determineSearchIntent($title); // Détection de l'intention
        $category = 'À définir'; // Placeholder pour la catégorisation

        // Ajouter les tendances au tableau
        $trends[] = [
            'title' => $title,
            'traffic' => $traffic,
            'growth_rate' => $growthRate,
            'category' => $category,
            'intent' => $intent
        ];
    }
}

// Trier les tendances par trafic estimé (ordre décroissant)
usort($trends, function ($a, $b) {
    $trafficA = intval(str_replace('+', '', $a['traffic']));
    $trafficB = intval(str_replace('+', '', $b['traffic']));
    return $trafficB <=> $trafficA;
});

// Calculer la popularité relative
$totalTraffic = array_sum(array_map('intval', array_map(function ($t) {
    return str_replace('+', '', $t['traffic']) ?: 0;
}, $trends)));

foreach ($trends as &$trend) {
    $traffic = intval(str_replace('+', '', $trend['traffic']) ?: 0);
    $trend['relative_popularity'] = $totalTraffic > 0 ? round(($traffic / $totalTraffic) * 100, 2) : 0;
}

// Enregistrer les tendances pour le suivi historique
$logFile = 'trends_log.json';
$logData = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : [];
$logData[] = [
    'date' => date('Y-m-d H:i:s'),
    'geo' => $geo,
    'trends' => $trends
];
file_put_contents($logFile, json_encode($logData, JSON_PRETTY_PRINT));

// Suggestions de mots-clés longue traîne
$keywords = [];
foreach ($trends as $trend) {
    $keywords[] = $trend['title'] . " conseils, idées, stratégies"; // Placeholder pour mots-clés
}

// Prompt pour générer des suggestions étendues de mots-clés
$prompt = "Voici une liste de mots-clés actuels liés aux tendances : 
" . implode(", ", $keywords) . ".

Pour chaque mot-clé, génère 5 variantes ou mots-clés longue traîne en fonction de ces catégories :
1. Informationnelle : Questions ou tutoriels (ex. : 'comment...', 'pourquoi...').
2. Transactionnelle : Requêtes pour acheter ou trouver un prix (ex. : 'acheter...', 'prix...').
3. Commerciale : Comparatifs ou avis (ex. : 'meilleur...', 'avis sur...').
4. Navigationnelle : Requêtes spécifiques à une marque ou un site.

Retourne les suggestions dans un format structuré :
- Mot-clé initial : Variantes suggérées
  - Informationnelle : ...
  - Transactionnelle : ...
  - Commerciale : ...
  - Navigationnelle : ...";

// Envoyer le prompt à OpenAI
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.openai.com/v1/chat/completions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer " . $_SESSION['apiKey'] // Utiliser la clé API de la session
]);

curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    "model" => "gpt-3.5-turbo",
    "messages" => [
        ["role" => "system", "content" => "Tu es un assistant SEO qui génère des mots-clés longue traîne."],
        ["role" => "user", "content" => $prompt]
    ],
    "max_tokens" => 1000
]));

$response = curl_exec($ch);
if ($response === false) {
    die("Erreur lors de la requête à OpenAI : " . curl_error($ch));
}
curl_close($ch);

// Extraire les suggestions étendues de la réponse de l'API
$responseData = json_decode($response, true);
$extendedKeywords = [];
if (isset($responseData["choices"][0]["message"]["content"])) {
    $extendedKeywords = explode("\n", $responseData["choices"][0]["message"]["content"]);
}


// Générer des recommandations SEO
$seoRecommendations = [];
foreach ($trends as $trend) {
    if ($trend['intent'] === 'Transactionnelle') {
        $seoRecommendations[] = "Créez une page produit ou une offre spéciale pour le mot-clé '{$trend['title']}'.";
    } elseif ($trend['intent'] === 'Informationnelle') {
        $seoRecommendations[] = "Rédigez un article ou un tutoriel détaillé pour '{$trend['title']}'.";
    } elseif ($trend['intent'] === 'Commerciale') {
        $seoRecommendations[] = "Créez un comparatif ou guide d'achat pour '{$trend['title']}'.";
    } else {
        $seoRecommendations[] = "Améliorez le branding et les annonces PPC pour '{$trend['title']}'.";
    }
}

// Prompt pour générer des données SEO enrichies avec IA
$promptSEO = "Tu es un assistant SEO expert. Voici une liste de mots-clés actuels : 
" . implode(", ", $keywords) . ".

Pour chaque mot-clé, génère les données suivantes :
1. Volume de recherche (Fort, Moyen, Faible).
2. Concurrence SEO (Faible, Moyenne, Élevée).
3. CPC moyen (en euros).
4. Difficulté SEO (pourcentage de 0 à 100).

Retourne les résultats dans ce format structuré :
- Mot-clé : [Nom du mot-clé]
  - Volume : [Fort/Moyen/Faible]
  - Concurrence : [Faible/Moyenne/Élevée]
  - CPC : [Valeur en euros]
  - Difficulté SEO : [Valeur en pourcentage].";

// Envoyer le prompt à OpenAI
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.openai.com/v1/chat/completions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer " . $_SESSION['apiKey'] // Utiliser la clé API de la session
]);

curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    "model" => "gpt-3.5-turbo",
    "messages" => [
        ["role" => "system", "content" => "Tu es un assistant SEO qui génère des données enrichies pour les mots-clés."],
        ["role" => "user", "content" => $promptSEO]
    ],
    "max_tokens" => 1500
]));

$responseSEO = curl_exec($ch);
if ($responseSEO === false) {
    die("Erreur lors de la requête à OpenAI : " . curl_error($ch));
}
curl_close($ch);

// Extraire les résultats de l'API
$responseDataSEO = json_decode($responseSEO, true);
$seoData = [];
if (isset($responseDataSEO["choices"][0]["message"]["content"])) {
    $lines = explode("\n", $responseDataSEO["choices"][0]["message"]["content"]);
    foreach ($lines as $line) {
        if (strpos($line, 'Mot-clé :') !== false) {
            preg_match('/Mot-clé : (.+)/', $line, $matches);
            $currentKeyword = $matches[1];
        } elseif (isset($currentKeyword)) {
            if (strpos($line, 'Volume :') !== false) {
                preg_match('/Volume : (.+)/', $line, $matches);
                $seoData[$currentKeyword]['volume'] = $matches[1];
            } elseif (strpos($line, 'Concurrence :') !== false) {
                preg_match('/Concurrence : (.+)/', $line, $matches);
                $seoData[$currentKeyword]['competition'] = $matches[1];
            } elseif (strpos($line, 'CPC :') !== false) {
                preg_match('/CPC : (.+)/', $line, $matches);
                $seoData[$currentKeyword]['cpc'] = $matches[1];
            } elseif (strpos($line, 'Difficulté SEO :') !== false) {
                preg_match('/Difficulté SEO : (.+)/', $line, $matches);
                $seoData[$currentKeyword]['difficulty'] = $matches[1];
            }
        }
    }
}


?>






<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars($metaDescription); ?>">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <!-- Inclure le fichier CSS -->
    <link rel="stylesheet" href="styles.css">
    <!-- Inclure la bibliothèque Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="container">
        <h1>Analyse des tendances actuelles dans la région <?php echo $geo; ?></h1>

        <!-- Sélecteur de région -->
        <form method="GET" action="">
            <label for="geo">Changer la région :</label>
            <select name="geo" id="geo">
                <option value="FR" <?php if ($geo === 'FR') echo 'selected'; ?>>France</option>
                <option value="US" <?php if ($geo === 'US') echo 'selected'; ?>>États-Unis</option>
                <option value="DE" <?php if ($geo === 'DE') echo 'selected'; ?>>Allemagne</option>
                <option value="GB" <?php if ($geo === 'GB') echo 'selected'; ?>>Royaume-Uni</option>
            </select>
            <button type="submit" class="button">Appliquer</button>
        </form>


<!-- Formulaire pour entrer la clé API OpenAI -->
<div class="api-key-container">
    <form method="POST" action="">
        <label for="apiKey">Entrez votre clé API OpenAI :</label>
        <input type="password" id="apiKey" name="apiKey" placeholder="sk-..." value="<?php echo htmlspecialchars($_SESSION['apiKey'] ?? ''); ?>">
        <button type="submit" class="button">Enregistrer</button>
    </form>
</div>



        <!-- Tableau des tendances -->
        <div class="card">
            <h2 class="section-title">Tableau des tendances</h2>
            <?php if (!empty($trends)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Tendance</th>
                            <th>Trafic estimé</th>
                            <th>Popularité relative</th>
                            <th>Catégorie</th>
                            <th>Intention</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trends as $trend): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($trend['title']); ?></td>
                            <td><?php echo htmlspecialchars($trend['traffic']); ?></td>
                            <td><?php echo $trend['relative_popularity'] . '%'; ?></td>
                            <td><?php echo htmlspecialchars($trend['category']); ?></td>
                            <td><?php echo htmlspecialchars($trend['intent']); ?></td>
                            <td>
                                <a href="https://www.google.com/search?q=<?php echo urlencode($trend['title']); ?>" target="_blank">Analyser les SERP</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Aucune tendance à afficher pour le moment.</p>
            <?php endif; ?>
        </div>

        <!-- Graphique des tendances -->
        <div class="card">
            <h2 class="section-title">Graphique des tendances</h2>
            <canvas id="trafficChart"></canvas>
        </div>

        <!-- Recommandations SEO -->
        <div class="card">
            <h2 class="section-title">Recommandations SEO</h2>
            <ul>
                <?php foreach ($seoRecommendations as $recommendation): ?>
                    <li><?php echo htmlspecialchars($recommendation); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Suggestions de mots-clés -->
        <div class="card">
            <h2 class="section-title">Suggestions de mots-clés</h2>
            <ul>
                <?php foreach ($keywords as $keyword): ?>
                    <li><?php echo htmlspecialchars($keyword); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<!-- Suggestions étendues de mots-clés -->
<div class="card">
    <h2 class="section-title">Suggestions étendues de mots-clés</h2>
    <?php if (!empty($extendedKeywords)): ?>
        <div class="accordion">
            <!-- Catégorie : Informationnelle -->
            <div class="accordion-item">
                <button class="accordion-button" onclick="AtoggleAccordion('info')">
                    <span>💡 Informationnelle</span>
                </button>
                <div class="accordion-content" id="accordion-info">
                    <ul>
                        <?php foreach ($extendedKeywords as $keyword): ?>
                            <?php if (strpos($keyword, 'Informationnelle') !== false): ?>
                                <li><?php echo htmlspecialchars($keyword); ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <!-- Catégorie : Transactionnelle -->
            <div class="accordion-item">
                <button class="accordion-button" onclick="AtoggleAccordion('transaction')">
                    <span>🛒 Transactionnelle</span>
                </button>
                <div class="accordion-content" id="accordion-transaction">
                    <ul>
                        <?php foreach ($extendedKeywords as $keyword): ?>
                            <?php if (strpos($keyword, 'Transactionnelle') !== false): ?>
                                <li><?php echo htmlspecialchars($keyword); ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <!-- Catégorie : Commerciale -->
            <div class="accordion-item">
                <button class="accordion-button" onclick="AtoggleAccordion('commercial')">
                    <span>📊 Commerciale</span>
                </button>
                <div class="accordion-content" id="accordion-commercial">
                    <ul>
                        <?php foreach ($extendedKeywords as $keyword): ?>
                            <?php if (strpos($keyword, 'Commerciale') !== false): ?>
                                <li><?php echo htmlspecialchars($keyword); ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <!-- Catégorie : Navigationnelle -->
            <div class="accordion-item">
                <button class="accordion-button" onclick="AtoggleAccordion('navigation')">
                    <span>🌐 Navigationnelle</span>
                </button>
                <div class="accordion-content" id="accordion-navigation">
                    <ul>
                        <?php foreach ($extendedKeywords as $keyword): ?>
                            <?php if (strpos($keyword, 'Navigationnelle') !== false): ?>
                                <li><?php echo htmlspecialchars($keyword); ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    <?php else: ?>
        <p>Aucune suggestion étendue disponible pour le moment.</p>
    <?php endif; ?>
</div>

<!-- Section Analyse SEO enrichie -->
<div class="card semrush-card">
    <h2 class="semrush-title">Analyse SEO enrichie</h2>
    <?php if (!empty($seoData)): ?>
        <table class="semrush-table">
            <thead>
                <tr>
                    <th>Mot-clé</th>
                    <th>Volume</th>
                    <th>Concurrence</th>
                    <th>CPC (€)</th>
                    <th>Difficulté SEO</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($seoData as $keyword => $data): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($keyword); ?></td>
                        <td><?php echo htmlspecialchars($data['volume'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($data['competition'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($data['cpc'] ?? 'N/A'); ?> €</td>
                        <td><?php echo htmlspecialchars($data['difficulty'] ?? 'N/A'); ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="semrush-no-data">Aucune donnée SEO enrichie disponible pour le moment.</p>
    <?php endif; ?>
</div>



        <!-- Historique des tendances -->
        <div class="card">
            <h2 class="section-title">Historique des tendances</h2>
            <div class="accordion">
                <?php foreach ($logData as $index => $log): ?>
                    <div class="accordion-item">
                        <button class="accordion-button" onclick="toggleAccordion(<?php echo $index; ?>)">
                            <?php echo htmlspecialchars($log['date']); ?> (Région : <?php echo htmlspecialchars($log['geo'] ?? 'Indéfini'); ?>)
                        </button>
                        <div class="accordion-content" id="accordion-content-<?php echo $index; ?>">
                            <ul>
                                <?php foreach ($log['trends'] as $trend): ?>
                                    <li><?php echo htmlspecialchars($trend['title']); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>



    <footer>
        © 2025 - Analyse SEO Dynamique
    </footer>

    <!-- Script pour générer le graphique -->
    <script>
        const labels = <?php echo json_encode(array_column($trends, 'title')); ?>;
        const data = <?php echo json_encode(array_map('intval', array_map(function($t) {
            return str_replace('+', '', $t['traffic']) ?: 0;
        }, $trends))); ?>;

        const ctx = document.getElementById('trafficChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Trafic estimé',
                    data: data,
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });

        function toggleAccordion(index) {
            const content = document.getElementById(`accordion-content-${index}`);
            const isVisible = content.style.display === 'block';
            content.style.display = isVisible ? 'none' : 'block';
        }
		
		function AtoggleAccordion(id) {
    const content = document.getElementById(`accordion-${id}`);
    const isVisible = content.style.display === 'block';
    document.querySelectorAll('.sugg-accordion-content').forEach(el => el.style.display = 'none'); // Fermer les autres
    content.style.display = isVisible ? 'none' : 'block';
}
    </script>
</body>
</html>
