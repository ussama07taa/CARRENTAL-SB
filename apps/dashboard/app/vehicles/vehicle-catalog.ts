// Suggestions for fleet entry, including older vehicles. Not a live sales catalog.
// Unknown makes/models remain editable and existing records keep their exact values.
// Moroccan range references: dacia.ma, volkswagen.ma/fr/nos-modeles.html,
// hyundai.com/ma/fr/build-a-car/select-a-car, eguide.renault.ma.
export const vehicleCatalog: Record<string, readonly string[]> = {
  Audi: ['A1', 'A3', 'A4', 'A5', 'A6', 'Q2', 'Q3', 'Q5', 'Q7', 'Q8'],
  BMW: ['Série 1', 'Série 2', 'Série 3', 'Série 4', 'Série 5', 'X1', 'X2', 'X3', 'X4', 'X5', 'X6'],
  Citroën: ['C1', 'C3', 'C3 Aircross', 'C4', 'C4 Cactus', 'C5 Aircross', 'Berlingo'],
  Dacia: ['Logan', 'Sandero', 'Sandero Stepway', 'Duster', 'Jogger', 'Lodgy', 'Dokker', 'Spring'],
  Fiat: ['500', '500X', 'Panda', 'Tipo', 'Punto', 'Doblo'],
  Ford: ['Fiesta', 'Focus', 'Fusion', 'Mondeo', 'Puma', 'Kuga', 'EcoSport'],
  Hyundai: ['i10', 'i20', 'i30', 'Accent', 'Elantra', 'Bayon', 'Kona', 'Tucson', 'Santa Fe'],
  Jeep: ['Renegade', 'Compass', 'Wrangler', 'Cherokee', 'Grand Cherokee'],
  Kia: ['Picanto', 'Rio', 'Ceed', 'Cerato', 'Stonic', 'Seltos', 'Sportage', 'Sorento', 'Niro'],
  'Land Rover': ['Defender', 'Discovery', 'Discovery Sport', 'Range Rover', 'Range Rover Sport', 'Range Rover Evoque', 'Range Rover Velar'],
  'Mercedes-Benz': ['Classe A', 'Classe B', 'Classe C', 'Classe E', 'Classe S', 'CLA', 'GLA', 'GLB', 'GLC', 'GLE', 'Classe V', 'Vito'],
  MINI: ['Cooper', 'Countryman', 'Clubman'],
  Nissan: ['Micra', 'Juke', 'Qashqai', 'X-Trail', 'Note'],
  Opel: ['Corsa', 'Astra', 'Mokka', 'Crossland', 'Grandland', 'Insignia'],
  Peugeot: ['108', '208', '308', '408', '508', '2008', '3008', '5008', 'Partner', 'Rifter'],
  Renault: ['Clio', 'Captur', 'Mégane', 'Scénic', 'Kadjar', 'Koleos', 'Arkana', 'Austral', 'Talisman', 'Kangoo', 'Express', 'Trafic', 'Zoe'],
  SEAT: ['Ibiza', 'Leon', 'Arona', 'Ateca', 'Tarraco'],
  Škoda: ['Fabia', 'Scala', 'Octavia', 'Superb', 'Kamiq', 'Karoq', 'Kodiaq'],
  Suzuki: ['Swift', 'Baleno', 'Celerio', 'Ignis', 'Vitara', 'Jimny', 'S-Cross'],
  Toyota: ['Yaris', 'Yaris Cross', 'Corolla', 'C-HR', 'RAV4', 'Camry', 'Land Cruiser', 'Hilux', 'Aygo'],
  Volkswagen: ['Polo', 'Golf', 'Passat', 'Jetta', 'T-Cross', 'T-Roc', 'Tiguan', 'Touareg', 'Touran', 'Caddy'],
  Volvo: ['S60', 'S90', 'V60', 'XC40', 'XC60', 'XC90'],
};
