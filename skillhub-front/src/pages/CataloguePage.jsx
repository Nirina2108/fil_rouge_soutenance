import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import formationService from '../services/formationService';
import inscriptionService from '../services/inscriptionService';
import { getFormationImage } from '../utils/formationImage';
import Navbar from '../components/Navbar';
import Footer from '../components/Footer';
import Bouton from '../components/Bouton';
import ModalAuth from '../components/ModalAuth';
import './CataloguePage.css';

/*
 * Page catalogue : liste de toutes les formations publiques avec filtres.
 *
 * Filtres disponibles : recherche texte, catégorie, niveau (état local
 * envoyé au backend via les query params de getFormations).
 *
 * Si l'utilisateur est connecté en tant qu'apprenant, le bouton "S'inscrire"
 * s'affiche sur chaque card. Sinon, un clic redirige vers la modal d'auth.
 *
 * Route : /formations (publique)
 */
export default function CataloguePage() {
    // Helpers du contexte : estConnecte() pour le wrapper public/privé,
    // estApprenant() pour décider d'afficher le bouton "S'inscrire".
    const { estConnecte, estApprenant } = useAuth();
    const navigate = useNavigate();

    // États : liste des formations + drapeau chargement + mode modal + notification toast.
    const [formations,  setFormations]  = useState([]);
    const [chargement,  setChargement]  = useState(true);
    const [modalMode,   setModalMode]   = useState(null);          // null | 'login' | 'register'
    const [notification, setNotification] = useState(null);        // { message, type }

    // Set des IDs des formations auxquelles l'apprenant connecté est déjà inscrit.
    // Set au lieu d'array pour avoir un .has() en O(1) au lieu d'O(n).
    const [inscritsIds, setInscritsIds] = useState(new Set());

    // États des filtres — synchronisés avec les inputs côté UI.
    const [recherche, setRecherche] = useState('');
    const [categorie, setCategorie] = useState('');
    const [niveau,    setNiveau]    = useState('');

    // Affiche un toast pendant 3.5 secondes puis le retire automatiquement.
    const afficherNotification = (message, type = 'succes') => {
        setNotification({ message, type });
        setTimeout(() => setNotification(null), 3500);
    };

    // Charge les formations depuis l'API en appliquant les filtres actuels.
    const chargerFormations = async () => {
        setChargement(true);
        try {
            // Construit l'objet filtres en n'incluant QUE les champs non vides.
            // Le backend reconnaît les query params seulement s'ils sont présents.
            const filtres = {};
            if (recherche) filtres.recherche = recherche;
            if (categorie) filtres.categorie = categorie;
            if (niveau)    filtres.niveau    = niveau;
            const data = await formationService.getFormations(filtres);
            setFormations(data);
        } catch (error) {
            console.error('Erreur chargement formations :', error);
        } finally {
            setChargement(false);
        }
    };

    // Charge les inscriptions de l'apprenant pour savoir quelles formations afficher comme "Inscrit".
    const chargerInscriptions = async () => {
        if (!estApprenant()) return;
        try {
            const data = await inscriptionService.mesFormations();
            // On crée un Set d'IDs pour des lookups rapides côté rendu.
            setInscritsIds(new Set(data.map((insc) => insc.formation_id)));
        } catch (error) {
            // Échec silencieux : on garde le Set vide, l'apprenant verra "S'inscrire".
            console.error('Erreur chargement inscriptions :', error);
        }
    };

    // Effect 1 : 1er chargement au montage (sans filtres) + inscriptions de l'apprenant.
    useEffect(() => {
        chargerFormations();
        chargerInscriptions();
    }, []);

    // Effect 2 : debounced re-fetch dès qu'un filtre change.
    // On attend 400ms après le dernier changement avant d'appeler l'API
    // pour éviter de saturer le backend pendant la frappe.
    useEffect(() => {
        const delai = setTimeout(() => { chargerFormations(); }, 400);
        // Cleanup : si un nouveau changement survient avant 400ms, on annule l'ancien timeout.
        return () => clearTimeout(delai);
    }, [recherche, categorie, niveau]);

    // Inscription à une formation depuis la card.
    const handleInscription = async (formationId) => {
        // Si pas connecté, on ouvre la modal d'auth au lieu d'appeler l'API.
        if (!estConnecte()) {
            setModalMode('login');
            return;
        }
        try {
            await inscriptionService.sInscrire(formationId);
            afficherNotification('Inscription reussie ! Retrouvez cette formation dans votre dashboard.', 'succes');
            // Mise à jour optimiste : on ajoute immédiatement l'id au Set local
            // pour que le bouton bascule en "Inscrit" sans attendre un refetch.
            // Le Set est immutable côté React : on en crée un nouveau via spread.
            setInscritsIds((prev) => new Set([...prev, formationId]));
        } catch (error) {
            const msg = error.response?.data?.message || 'Erreur inscription';
            // Cas spécial : déjà inscrit -> message d'info + on synchronise le Set local.
            if (msg.includes('deja')) {
                afficherNotification('Vous etes deja inscrit a cette formation.', 'info');
                setInscritsIds((prev) => new Set([...prev, formationId]));
            } else {
                afficherNotification(msg, 'erreur');
            }
        }
    };

    // Reset les 3 filtres en une fois.
    const reinitialiserFiltres = () => {
        setRecherche('');
        setCategorie('');
        setNiveau('');
    };

    // Helper extrayant la branche conditionnelle (chargement / vide / liste).
    // Évite le ternaire imbriqué que SonarLint flagge (javascript:S3358).
    const renderFormationsListe = () => {
        if (chargement) {
            return <p className="catalogue-chargement">Chargement...</p>;
        }
        if (formations.length === 0) {
            return <p className="catalogue-vide">Aucune formation trouvee.</p>;
        }
        return (
            <>
                <p className="catalogue-compteur">
                    {formations.length} formation{formations.length > 1 ? 's' : ''} trouvee{formations.length > 1 ? 's' : ''}
                </p>
                <div className="catalogue-grille">
                    {formations.map((formation) => (
                        <div key={formation.id} className="catalogue-card">
                            {/* Image attribuée automatiquement par rotation parmi les 7 photos
                                bundlées dans /public/images/formation/ (cf. utils/formationImage.js).
                                Pas d'upload côté formateur : cohérence visuelle garantie. */}
                            <img
                                src={getFormationImage(formation)}
                                alt={formation.titre}
                                className="catalogue-card-image"
                            />
                            <div className="catalogue-card-badges">
                                <span className="catalogue-badge-niveau">{formation.niveau}</span>
                                <span className="catalogue-badge-categorie">{formation.categorie?.replace('_', ' ')}</span>
                            </div>
                            <h3 className="catalogue-card-titre">{formation.titre}</h3>
                            <p className="catalogue-card-description">
                                {formation.description?.slice(0, 100)}
                                {formation.description?.length > 100 ? '...' : ''}
                            </p>
                            <div className="catalogue-card-meta">
                                <span>Par {formation.formateur?.nom}</span>
                                <span>{formation.inscriptions_count} apprenant{formation.inscriptions_count > 1 ? 's' : ''}</span>
                                <span>{formation.nombre_de_vues} vue{formation.nombre_de_vues > 1 ? 's' : ''}</span>
                            </div>
                            <div className="catalogue-card-actions">
                                <Bouton variante="fantome" taille="petit" onClick={() => navigate(`/formation/${formation.id}`)}>
                                    Voir detail
                                </Bouton>
                                {/* Apprenant connecté : bouton "Inscrit ✓ Continuer" si déjà inscrit, sinon "S'inscrire" */}
                                {estApprenant() && inscritsIds.has(formation.id) && (
                                    <Bouton
                                        variante="secondaire"
                                        taille="petit"
                                        onClick={() => navigate(`/apprendre/${formation.id}`)}
                                    >
                                        ✓ Inscrit — Continuer
                                    </Bouton>
                                )}
                                {estApprenant() && !inscritsIds.has(formation.id) && (
                                    <Bouton variante="principal" taille="petit" onClick={() => handleInscription(formation.id)}>
                                        S'inscrire
                                    </Bouton>
                                )}
                                {!estConnecte() && (
                                    <Bouton variante="principal" taille="petit" onClick={() => setModalMode('login')}>
                                        Suivre la formation
                                    </Bouton>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </>
        );
    };

    return (
        <div className="catalogue-page">
            <Navbar />

            {/* Notification fixe en bas a droite */}
            {notification && (
                <div className={`catalogue-notification catalogue-notification-${notification.type}`}>
                    <span className="catalogue-notif-icone">
                        {notification.type === 'succes' ? '✓' : notification.type === 'info' ? 'ℹ' : '✕'}
                    </span>
                    <span>{notification.message}</span>
                    <button className="catalogue-notif-fermer" onClick={() => setNotification(null)}>✕</button>
                </div>
            )}

            <div className="catalogue-contenu">
                <h1 className="catalogue-titre">Toutes les formations</h1>

                <div className="catalogue-filtres">
                    <input
                        type="text"
                        placeholder="Rechercher une formation..."
                        value={recherche}
                        onChange={(e) => setRecherche(e.target.value)}
                        className="catalogue-input-recherche"
                    />
                    <select value={categorie} onChange={(e) => setCategorie(e.target.value)} className="catalogue-select">
                        <option value="">Toutes les categories</option>
                        <option value="developpement_web">Developpement web</option>
                        <option value="data">Data</option>
                        <option value="design">Design</option>
                        <option value="marketing">Marketing</option>
                        <option value="devops">DevOps</option>
                        <option value="autre">Autre</option>
                    </select>
                    <select value={niveau} onChange={(e) => setNiveau(e.target.value)} className="catalogue-select">
                        <option value="">Tous les niveaux</option>
                        <option value="debutant">Debutant</option>
                        <option value="intermediaire">Intermediaire</option>
                        <option value="avance">Avance</option>
                    </select>
                    {(recherche || categorie || niveau) && (
                        <Bouton variante="secondaire" taille="petit" onClick={reinitialiserFiltres}>
                            Reinitialiser
                        </Bouton>
                    )}
                </div>

                {renderFormationsListe()}
            </div>

            <Footer />

            {modalMode && (
                <ModalAuth mode={modalMode} onFermer={() => setModalMode(null)} />
            )}
        </div>
    );
}