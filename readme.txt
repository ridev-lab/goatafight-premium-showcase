=== GoataFight Premium ===
Contributors: goatafight
Tags: premium, paywall, reader revenue manager, subscribe with google, adsense
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later

GoataFight Premium + Google Reader Revenue Manager.

== Fonctionnalités ==

* Conserve la suppression AdSense du plugin GoataFight Premium - No Ads.
* Utilise le Product ID fourni dans la configuration WordPress (constante GOATAFIGHT_RRM_PRODUCT_ID).
* Paywall partiel avec un simple marqueur : [goata_paywall]
* Le contenu situé après le marqueur n'est pas présent dans le HTML initial du visiteur.
* Vérification RRM via swg.js / getEntitlements().
* Chargement de la suite Premium après validation.
* CTA GoataFight noir / jaune avec bouton RRM natif.
* Support LiteSpeed Cache.
* Balisage Rank Math pour contenu payant.
* Mode de test administrateur.

== Installation ==

1. Désactiver puis supprimer l'ancien plugin "GoataFight Premium - No Ads" afin d'éviter que les deux codes tournent en même temps.
2. Installer ce plugin ZIP dans WordPress > Extensions > Ajouter une extension > Téléverser une extension.
3. Activer "GoataFight Premium".
4. Vérifier que Site Kit / Reader Revenue Manager charge bien swg.js sur le site.

== Utilisation ==

Dans un article :

Contenu gratuit...

[goata_paywall]

Contenu Premium...

Tout ce qui est AVANT reste public. Tout ce qui est APRÈS est chargé uniquement après validation de l'accès Premium.

== Exemple recommandé GoataFight ==

Gratuit :
- introduction
- fiches combattants
- tableau de statistiques
- GoataMetrics
- premières tendances

Puis :
[goata_paywall]

Premium :
- analyse croisée des données
- failles techniques
- scénarios de victoire
- pronostic final
- méthode / round / value éventuelle

== Test administrateur ==

Connecté en administrateur :

?goata_premium_test=locked
Force l'affichage du paywall.

?goata_premium_test=premium
Affiche directement la partie Premium.

Exemple :
https://goatafight.com/mon-article/?goata_premium_test=locked

== Contrôle des accès ==

* Accès payant validé côté serveur à partir d'un justificatif d'abonnement signé par Google.
* Vérification de l'expiration et de la signature, puis création d'une session courte côté serveur.
* Les accès Premium offerts utilisent le compte WordPress et une date d'expiration.
* Les accès aux contenus Premium restent soumis au contrôle serveur à chaque requête AJAX.

== Cache ==

La page publique reste cacheable : elle est identique pour tout le monde et contient seulement la partie gratuite + CTA.
Le contenu Premium est chargé séparément avec des headers no-cache après validation.
