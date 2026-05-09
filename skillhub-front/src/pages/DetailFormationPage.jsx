import { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import formationService from '../services/formationService';
import moduleService from '../services/moduleService';
import inscriptionService from '../services/inscriptionService';
import { obtenirImageFormation } from '../utils/formationImage';
import { formaterPrix, estGratuite } from '../utils/formaterPrix';
import Navbar from '../components/Navbar';
import Footer from '../components/Footer';
import Bouton from '../components/Bouton';
import ModalAuth from '../components/ModalAuth';
import ModalPaiement from '../components/ModalPaiement';
import './DetailFormationPage.css';

/*
 * Page de détail d'une formation, accessible publiquement.
 *
 * Affiche les informations complètes de la formation, son formateur, la
 * liste des modules (titre seul, contenu masqué pour les non-inscrits),
 * un bouton d'inscription pour les apprenants, ou "Continuer" pour les
 * apprenants déjà inscrits (renvoie vers ApprendrePage).
 *
 * Route : /formation/:id (publique, l'inscription requiert une session apprenant)
 */
export default function DetailFormationPage() {
    // useParams récupère les segments dynamiques de l'URL (ici :id depuis /formation/:id).
    const { id }     = useParams();
    const navigate   = useNavigate();
    const { estConnecte, estApprenant } = useAuth();

    // État : formation chargée, ses modules, statut UI, et drapeau inscription.
    const [formation,    setFormation]    = useState(null);
    const [modules,      setModules]      = useState([]);
    const [chargement,   setChargement]   = useState(true);
    const [erreur,       setErreur]       = useState('');
    const [messageOk,    setMessageOk]    = useState('');
    const [modalMode,    setModalMode]    = useState(null);
    // Drapeau d'ouverture de la ModalPaiement (formation payante).
    const [modalPaiementOuverte, setModalPaiementOuverte] = useState(false);
    const [inscrit,      setInscrit]      = useState(false);     // l'apprenant courant est-il inscrit ?
    const [loadingInsc,  setLoadingInsc]  = useState(false);     // pendant la requête d'inscription

    // Effect : chargement initial de la page (relance si l'id change).
    useEffect(() => {
        const charger = async () => {
            try {
                // Promise.all = appels parallèles pour gagner du temps (formation + modules).
                const [dataFormation, dataModules] = await Promise.all([
                    formationService.recupererFormation(id),
                    moduleService.recupererModules(id),
                ]);

                setFormation(dataFormation);
                setModules(dataModules);

                // Si l'utilisateur est apprenant, on regarde si déjà inscrit pour
                // afficher "Continuer" au lieu de "Suivre".
                if (estApprenant()) {
                    const mesFormations = await inscriptionService.mesFormations();
                    // .some() : true s'il y a au moins une inscription matching cette formation.
                    const dejaInscrit   = mesFormations.some(
                        (insc) => insc.formation_id === parseInt(id)
                    );
                    setInscrit(dejaInscrit);
                }
            } catch (error) {
                setErreur('Formation introuvable.');
            } finally {
                setChargement(false);
            }
        };
        charger();
    }, [id]);

    // Inscription depuis cette page (bouton "Suivre la formation").
    // Branchement paiement : si prix > 0, on ouvre la ModalPaiement (re-auth requise).
    // Si prix = 0, on appelle directement l'endpoint d'inscription (comportement historique).
    const handleInscription = async () => {
        // Pas connecté -> ouvre la modal d'auth, l'utilisateur s'inscrit puis revient.
        if (!estConnecte()) {
            setModalMode('login');
            return;
        }

        // Formation payante : ouverture de la ModalPaiement.
        if (parseFloat(formation?.prix) > 0) {
            setModalPaiementOuverte(true);
            return;
        }

        // Formation gratuite : flow historique (inscription directe).
        setLoadingInsc(true);
        try {
            await inscriptionService.sInscrire(id);
            setInscrit(true);  // bascule l'UI vers "Continuer"
            setMessageOk('Inscription réussie ! Vous pouvez maintenant suivre cette formation.');
        } catch (error) {
            const msg = error.response?.data?.message || "Erreur lors de l'inscription.";
            setErreur(msg);
        } finally {
            setLoadingInsc(false);
        }
    };

    // Callback appelé par ModalPaiement après paiement réussi.
    const handlePaiementSucces = () => {
        setModalPaiementOuverte(false);
        setInscrit(true);
        setMessageOk('Paiement confirmé ! Vous êtes inscrit à la formation.');
    };

    // Convertit la valeur niveau en libellé affichable.
    const getNiveauLabel = (niveau) => {
        const labels = {
            debutant:      'Débutant',
            intermediaire: 'Intermédiaire',
            avance:        'Avancé',
        };
        return labels[niveau] || niveau;
    };

    // Convertit la valeur categorie en libellé affichable (ex: 'developpement_web' -> 'Développement web').
    const getCategorieLabel = (categorie) => {
        const labels = {
            developpement_web: 'Développement web',
            data:              'Data',
            design:            'Design',
            marketing:         'Marketing',
            devops:            'DevOps',
            autre:             'Autre',
        };
        return labels[categorie] || categorie;
    };

    // Early return : pendant le chargement initial, on affiche un placeholder.
    if (chargement) {
        return (
            <div className="detail-page">
                <Navbar />
                <p className="detail-chargement">Chargement...</p>
                <Footer />
            </div>
        );
    }

    // Early return : si erreur ET pas de formation chargée, page d'erreur dédiée.
    if (erreur && !formation) {
        return (
            <div className="detail-page">
                <Navbar />
                <div className="detail-erreur-page">
                    <p>{erreur}</p>
                    <Bouton variante="secondaire" onClick={() => navigate('/formations')}>
                        Retour aux formations
                    </Bouton>
                </div>
                <Footer />
            </div>
        );
    }

    return (
        <div className="detail-page">
            <Navbar />

            <div className="detail-contenu">

                {/* En-tête de la formation */}
                <div className="detail-entete">
                    {/* Image hero attribuée par rotation parmi les 7 photos bundlées. */}
                    <img
                        src={obtenirImageFormation(formation)}
                        alt={formation.titre}
                        className="detail-image"
                    />

                    <div className="detail-badges">
                        <span className="detail-badge-niveau">
                            {getNiveauLabel(formation.niveau)}
                        </span>
                        <span className="detail-badge-categorie">
                            {getCategorieLabel(formation.categorie)}
                        </span>
                        {/* Badge prix bien visible sur la page détail (décision d'achat). */}
                        <span className={`detail-badge-prix ${estGratuite(formation.prix) ? 'detail-badge-prix-gratuit' : ''}`}>
                            {formaterPrix(formation.prix)}
                        </span>
                    </div>

                    <h1 className="detail-titre">{formation.titre}</h1>
                    <p className="detail-description">{formation.description}</p>

                    <div className="detail-meta">
                        <span>Formateur : <strong>{formation.formateur?.nom}</strong></span>
                        <span>{formation.inscriptions_count} apprenant{formation.inscriptions_count > 1 ? 's' : ''}</span>
                        <span>{formation.nombre_de_vues} vue{formation.nombre_de_vues > 1 ? 's' : ''}</span>
                    </div>

                    {/* Messages */}
                    {messageOk && (
                        <p className="detail-succes">{messageOk}</p>
                    )}
                    {erreur && (
                        <p className="detail-erreur">{erreur}</p>
                    )}

                    {/* Actions */}
                    <div className="detail-actions">
                        {inscrit ? (
                            <Bouton
                                variante="principal"
                                taille="grand"
                                onClick={() => navigate(`/apprendre/${id}`)}
                            >
                                Continuer la formation
                            </Bouton>
                        ) : (
                            <Bouton
                                variante="principal"
                                taille="grand"
                                onClick={handleInscription}
                                disabled={loadingInsc}
                            >
                                {loadingInsc ? 'Inscription...' : 'Suivre la formation'}
                            </Bouton>
                        )}

                        <Bouton
                            variante="secondaire"
                            taille="grand"
                            onClick={() => navigate('/formations')}
                        >
                            Retour aux formations
                        </Bouton>
                    </div>
                </div>

                {/* Liste des modules de la formation (titres seulement, contenu masqué pour les non-inscrits) */}
                <div className="detail-modules">
                    <h2 className="detail-modules-titre">
                        {/* Pluriel conditionnel selon le nombre de modules */}
                        Contenu de la formation ({modules.length} module{modules.length > 1 ? 's' : ''})
                    </h2>

                    {/* Ternaire simple : si aucun module, message ; sinon, liste */}
                    {modules.length === 0 ? (
                        <p className="detail-modules-vide">
                            Aucun module disponible pour le moment.
                        </p>
                    ) : (
                        <div className="detail-modules-liste">
                            {modules.map((module, index) => (
                                <div key={module.id} className="detail-module-item">
                                    {/* Numéro affiché basé sur l'index (commence à 1) */}
                                    <div className="detail-module-numero">
                                        {index + 1}
                                    </div>
                                    <div className="detail-module-info">
                                        <h3 className="detail-module-titre">
                                            {module.titre}
                                        </h3>
                                        <p className="detail-module-apercu">
                                            {/* Aperçu : 80 premiers caractères + ellipse si tronqué */}
                                            {module.contenu?.slice(0, 80)}
                                            {module.contenu?.length > 80 ? '...' : ''}
                                        </p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

            </div>

            <Footer />

            {modalMode && (
                <ModalAuth
                    mode={modalMode}
                    onFermer={() => setModalMode(null)}
                />
            )}

            {/* Modal de paiement : montée seulement si l'apprenant a cliqué Suivre sur formation payante. */}
            {modalPaiementOuverte && formation && (
                <ModalPaiement
                    formation={formation}
                    onFermer={() => setModalPaiementOuverte(false)}
                    onSucces={handlePaiementSucces}
                />
            )}
        </div>
    );
}