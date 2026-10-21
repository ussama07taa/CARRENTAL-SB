import {ArrowDownLeft, CalendarDays, Check, CheckCircle2, Clock3, LogIn, RotateCcw, Wrench} from 'lucide-react';
import FieldHelp from '../_components/field-help';

const choices = {
  plan: {title: 'Prévoir une date', detail: 'Fixer un rappel. La voiture ne rentre pas au garage.', icon: CalendarDays},
  start: {title: 'La voiture entre au garage', detail: 'Enregistrer son entrée réelle pour reprendre ce travail.', icon: LogIn},
  complete: {title: 'La prestation est terminée', detail: 'Le travail est fait. Le retour de la voiture se note ensuite.', icon: CheckCircle2},
  defer: {title: 'Reporter à nouveau', detail: 'Le travail attend encore. Indiquez le motif du report.', icon: RotateCcw},
  return: {title: 'La voiture est revenue', detail: 'Elle est revenue à l’agence et prête à reprendre le service.', icon: ArrowDownLeft},
};

export default function MaintenanceFollowupGuide({visit, status, initialOpen, action, onChange, error}: {visit: boolean; status: string; initialOpen: boolean; action: string; onChange: (value: string) => void; error?: string}) {
  const available: (keyof typeof choices)[] = visit ? status === 'progress' ? ['complete', 'defer'] : ['return'] : ['plan', 'start', 'complete'];
  const current = visit ? status === 'progress' ? 1 : 2 : 0;
  const steps = [{title: 'Entrée au garage', description: 'La voiture est confiée au garage', icon: LogIn}, {title: 'Travail terminé', description: 'La prestation est réalisée', icon: Wrench}, {title: 'Retour à l’agence', description: 'La voiture est récupérée', icon: ArrowDownLeft}];
  return <div className="mf-followup-guide">
    <div className="mf-journey" aria-label="Les trois étapes de cette reprise">{steps.map((step, index) => <div key={step.title} className={index < current ? 'done' : index === current ? 'current' : ''} aria-current={index === current ? 'step' : undefined}><span className="mf-step-number">{index < current ? <Check size={15}/> : index + 1}</span><div><strong>{step.title}</strong><small>{step.description}</small></div></div>)}</div>
    <div className="mf-guide-context"><Clock3 size={17}/><span>{visit ? status === 'progress' ? 'La voiture est au garage. Enregistrez maintenant le résultat du travail.' : status === 'done' ? 'Le travail est terminé. Il reste à enregistrer le retour réel de la voiture.' : 'Le travail est de nouveau reporté. Enregistrez le retour quand la voiture revient.' : 'Ce travail est reporté. Choisissez ce qui se passe réellement aujourd’hui.'}</span></div>
    <fieldset tabIndex={-1} className="mf-action-options" data-error-key="action" aria-invalid={!!error} aria-describedby={error ? 'mf-action-error' : undefined}>
      <legend>Que souhaitez-vous enregistrer ? <FieldHelp field="action" label="Suivi du travail"/></legend>
      <div>{available.map(key => {const choice = choices[key], Icon = choice.icon; const blocked = key === 'start' && initialOpen;return <button type="button" key={key} aria-pressed={action === key} disabled={blocked} onClick={() => onChange(key)}><Icon size={21}/><span><strong>{!visit && key === 'complete' ? 'Travail déjà réalisé' : choice.title}</strong><small>{blocked ? 'Enregistrez d’abord le retour de la visite initiale.' : !visit && key === 'complete' ? 'Enregistrer une prestation déjà faite, sans nouvelle visite à suivre.' : choice.detail}</small></span>{action === key && <Check size={17}/>}</button>;})}</div>
      {error && <small id="mf-action-error" className="gf-field-error">{error}</small>}
    </fieldset>
    <p className="mf-form-purpose">{({plan: 'Indiquez quand vous souhaitez reprendre ce travail.', start: 'Indiquez quand la voiture est réellement entrée au garage.', complete: 'Indiquez quand le travail a réellement été terminé.', defer: 'Expliquez pourquoi le travail reste à faire.', return: 'Confirmez la date du retour et le contrôle de la voiture.'})[action]}</p>
  </div>;
}
