import { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import formationService from '../services/formationService';
import moduleService from '../services/moduleService';
import inscriptionService from '../services/inscriptionService';
import Navbar from '../components/Navbar';
import Footer from '../components/Footer';
import Bouton from '../components/Bouton';
import './ApprendrePage.css';

/*
 * Page d'apprentissage : où l'apprenant inscrit consomme la formation.
 *
 * Layout : sidebar gauche avec la liste des modules (numéro et titre, coche
 * si terminé), zone centrale avec le contenu du module sélectionné, barre
 * de progression en haut. Boutons "Lire le PDF" et "Télécharger" si la
 * formation a un PDF de cours uploadé par le formateur.
 *
 * Sécurité : si l'apprenant n'est pas inscrit, on redirige vers son dashboard.
 *
 * Route : /apprendre/:id (réservée aux apprenants inscrits à cette formation)
 */
export default function ApprendrePage() {
    const { id }   = useParams();          // id de la formation depuis l'URL /apprendre/:id
    const navigate = useNavigate();
    const { utilisateur } = useAuth();

    // États : formation + modules + inscription + progression + UI flags.
    const [formation,    setFormation]    = useState(null);
    const [modules,      setModules]      = useState([]);
    const [inscription,  setInscription]  = useState(null);     // pour la progression %
    const [modulesTermines, setModulesTermines] = useState([]); // tableau d'IDs des modules finis
    const [chargement,   setChargement]   = useState(true);
    const [erreur,       setErreur]       = useState('');
    const [messageOk,    setMessageOk]    = useState('');
    const [moduleActif,  setModuleActif]  = useState(null);     // module actuellement affiché
    const [loadingTerminer, setLoadingTerminer] = useState(false);

    // Chargement initial : 4 requêtes en parallèle pour gagner du temps.
    const charger = async () => {
        try {
            // Promise.all attend que les 4 résolvent puis renvoie un tableau de résultats.
            const [dataFormation, dataModules, mesFormations, termines] = await Promise.all([
                formationService.recupererFormation(id),
                moduleService.recupererModules(id),
                inscriptionService.mesFormations(),
                moduleService.recupererMesModulesTermines(id),
            ]);

            setFormation(dataFormation);
            setModules(dataModules);

            // Pré-remplir les modules déjà terminés (persistés en base, pour cocher les items).
            setModulesTermines(termines.modules_termines ?? []);

            // Cherche l'inscription correspondant à cette formation parmi celles de l'apprenant.
            // parseInt nécessaire car id de l'URL est string, alors que formation_id est int.
            const insc = mesFormations.find(
                (i) => parseInt(i.formation_id) === parseInt(id)
            );

            // Sécurité : si pas inscrit, on redirige vers le dashboard.
            // L'apprenant ne peut pas voir le contenu d'une formation à laquelle il n'est pas inscrit.
            if (!insc) {
                navigate('/dashboard/apprenant');
                return;
            }

            setInscription(insc);

            // Module initial : on ouvre le 1er par défaut pour ne pas afficher un écran vide.
            if (dataModules.length > 0) {
                setModuleActif(dataModules[0]);
            }

        } catch (error) {
            setErreur('Erreur lors du chargement de la formation.');
        } finally {
            setChargement(false);
        }
    };

    // Effect de chargement, relance si l'id change (navigation entre formations).
    useEffect(() => {
        charger();
    }, [id]);

    // Ouvre le PDF dans un nouvel onglet (lecture inline, pas téléchargement).
    const handleVoirPdf = async () => {
        try {
            await formationService.ouvrirPdf(formation.id);
        } catch (error) {
            const msg = error.response?.data?.message || 'Erreur lors de l\'ouverture du PDF.';
            setErreur(msg);
            setTimeout(() => setErreur(''), 3000);
        }
    };

    // Télécharge le PDF sur le poste de l'utilisateur.
    const handleTelechargerPdf = async () => {
        try {
            await formationService.telechargerPdf(formation.id, formation.titre);
        } catch (error) {
            const msg = error.response?.data?.message || 'Erreur lors du téléchargement.';
            setErreur(msg);
            setTimeout(() => setErreur(''), 3000);
        }
    };

    // Marque un module comme terminé et met à jour la progression locale.
    const handleTerminer = async (moduleId) => {
        // Idempotence : si déjà terminé, on ne fait rien (pas d'appel API).
        if (modulesTermines.includes(moduleId)) return;

        setLoadingTerminer(true);
        try {
            const data = await moduleService.terminerModule(moduleId);
            // Mise à jour du tableau des modules terminés avec spread operator (immutabilité React).
            setModulesTermines([...modulesTermines, moduleId]);
            // Mise à jour de la progression dans l'inscription (object spread pour copier).
            setInscription({ ...inscription, progression: data.progression });
            // Message de succès avec la nouvelle progression (template literal).
            setMessageOk(`Module terminé. Progression : ${data.progression}%`);
            setTimeout(() => setMessageOk(''), 3000);
        } catch (error) {
            const msg = error.response?.data?.message || 'Erreur lors de la progression.';
            setErreur(msg);
            setTimeout(() => setErreur(''), 3000);
        } finally {
            setLoadingTerminer(false);
        }
    };

    // Helper pour les classes CSS et le badge "Terminé".
    const estTermine = (moduleId) => modulesTermines.includes(moduleId);

    if (chargement) {
        return (
            <div className="apprendre-page">
                <Navbar />
                <p className="apprendre-chargement">Chargement...</p>
                <Footer />
            </div>
        );
    }

    if (erreur && !formation) {
        return (
            <div className="apprendre-page">
                <Navbar />
                <div className="apprendre-erreur-page">
                    <p>{erreur}</p>
                    <Bouton variante="secondaire" onClick={() => navigate('/dashboard/apprenant')}>
                        Retour au dashboard
                    </Bouton>
                </div>
                <Footer />
            </div>
        );
    }

    return (
        <div className="apprendre-page">
            <Navbar />

            <div className="apprendre-contenu">

                {/* En-tête formation */}
                <div className="apprendre-entete">
                    <div className="apprendre-entete-info">
                        <button
                            className="apprendre-retour"
                            onClick={() => navigate('/dashboard/apprenant')}
                        >
                            ← Retour au dashboard
                        </button>
                        <h1 className="apprendre-titre">{formation?.titre}</h1>
                        <p className="apprendre-description">{formation?.description}</p>

                        {formation?.fichier_pdf && (
                            <div className="apprendre-pdf-actions">
                                <Bouton variante="principal" taille="petit" onClick={handleVoirPdf}>
                                    📖 Lire le cours PDF
                                </Bouton>
                                <Bouton variante="secondaire" taille="petit" onClick={handleTelechargerPdf}>
                                    ⬇ Télécharger
                                </Bouton>
                            </div>
                        )}
                    </div>

                    {/* Progression globale */}
                    <div className="apprendre-progression-bloc">
                        <div className="apprendre-progression-header">
                            <span className="apprendre-progression-label">Progression globale</span>
                            <span className="apprendre-progression-valeur">
                                {inscription?.progression}%
                            </span>
                        </div>
                        <div className="apprendre-progression-barre">
                            <div
                                className="apprendre-progression-remplissage"
                                style={{ width: `${inscription?.progression}%` }}
                            />
                        </div>
                        {inscription?.progression === 100 && (
                            <p className="apprendre-felicitations">
                                Félicitations, vous avez terminé cette formation !
                            </p>
                        )}
                    </div>
                </div>

                {/* Messages */}
                {messageOk && <p className="apprendre-succes">{messageOk}</p>}
                {erreur    && <p className="apprendre-erreur">{erreur}</p>}

                <div className="apprendre-layout">

                    {/* Liste des modules — colonne gauche */}
                    <div className="apprendre-sidebar">
                        <h2 className="apprendre-sidebar-titre">Modules</h2>
                        <div className="apprendre-modules-liste">
                            {modules.map((module, index) => (
                                <button
                                    key={module.id}
                                    className={`apprendre-module-item ${moduleActif?.id === module.id ? 'apprendre-module-actif' : ''} ${estTermine(module.id) ? 'apprendre-module-termine' : ''}`}
                                    onClick={() => setModuleActif(module)}
                                >
                                    <span className="apprendre-module-numero">
                                        {estTermine(module.id) ? '✓' : index + 1}
                                    </span>
                                    <span className="apprendre-module-nom">
                                        {module.titre}
                                    </span>
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* Contenu du module actif — colonne droite */}
                    <div className="apprendre-module-contenu">
                        {moduleActif ? (
                            <>
                                <div className="apprendre-module-entete">
                                    <h2 className="apprendre-module-titre">
                                        {moduleActif.titre}
                                    </h2>
                                    {estTermine(moduleActif.id) ? (
                                        <span className="apprendre-badge-termine">
                                            Terminé
                                        </span>
                                    ) : (
                                        <Bouton
                                            variante="principal"
                                            taille="petit"
                                            onClick={() => handleTerminer(moduleActif.id)}
                                            disabled={loadingTerminer}
                                        >
                                            {loadingTerminer ? 'Enregistrement...' : 'Marquer comme terminé'}
                                        </Bouton>
                                    )}
                                </div>

                                <div className="apprendre-module-texte">
                                    {moduleActif.contenu}
                                </div>

                                {/* Navigation entre modules */}
                                <div className="apprendre-navigation">
                                    <Bouton
                                        variante="secondaire"
                                        taille="petit"
                                        onClick={() => {
                                            const index = modules.findIndex(m => m.id === moduleActif.id);
                                            if (index > 0) setModuleActif(modules[index - 1]);
                                        }}
                                        disabled={modules.findIndex(m => m.id === moduleActif.id) === 0}
                                    >
                                        ← Module précédent
                                    </Bouton>
                                    <Bouton
                                        variante="secondaire"
                                        taille="petit"
                                        onClick={() => {
                                            const index = modules.findIndex(m => m.id === moduleActif.id);
                                            if (index < modules.length - 1) setModuleActif(modules[index + 1]);
                                        }}
                                        disabled={modules.findIndex(m => m.id === moduleActif.id) === modules.length - 1}
                                    >
                                        Module suivant →
                                    </Bouton>
                                </div>
                            </>
                        ) : (
                            <p className="apprendre-vide">
                                Sélectionnez un module pour commencer.
                            </p>
                        )}
                    </div>

                </div>
            </div>

            <Footer />
        </div>
    );
}