<?php

namespace Goteo\Model {

    use Goteo\Library\Check,
        Goteo\Library\Text;

    class Project extends \Goteo\Core\Model {
        
        public        
            $id = null,
            $owner, // User who created it
            $node, // Node this project belongs to
            $status,
            $progress, // puntuation from  1 to 100 for the fiuuu
            $amount, // Current donated amount

            // Register contract data
            $contract_name,
            $contract_surname,
            $contract_nif, // Guardar sin espacios ni puntos ni guiones
            $contract_email,
            $phone, // guardar sin espacios ni puntos
            $address,
            $zipcode,
            $location, // owner's location
            $country,

            // Edit project description
            $name,
            $image,
            $description,
             $motivation,
             $about,
             $goal,
             $related,
            $categories = array(),
            $media,
            $keywords, // por ahora se guarda en texto tal cual
            $currently, // Current development status of the project
            $project_location, // project execution location
                
            // costs
            $costs = array(),  // project\cost instances with type
            $schedule, // picture of the costs schedule
            $resource, // other current resources
            
            // Rewards
            $social_rewards = array(), // instances of project\reward for the public (collective type)
            $individual_rewards = array(), // instances of project\reward for investors  (individual type)

            // Collaborations
            $supports = array(), // instances of project\support

            //Operative purpose properties
            $mincost = 0,
            $maxcost = 0,
            $badfields = array(), // para guardar los errores del formulario y mostrarlos en elos campos del paso
            $fiuuu = '';

        /*
         *  Cargamos los datos del usuario al crear la instancia
         */
        public static function get($id) {
            try {
				// metemos los datos del proyecto en la instancia
				$query = self::query("SELECT * FROM project WHERE id = ?", array($id));
				$project = $query->fetchObject(__CLASS__);

				// categorias
				$query = self::query("SELECT category FROM project_category WHERE project = ?", array($id));
                $cats = $query->fetchAll();
                foreach ($cats as $cat) {
                    $project->categories[] = $cat[0];
                }

				// costes y los sumammos
				$project->costs = Project\Cost::getAll($id);

				foreach ($project->costs as $item) {
					if ($item->required == 1) {
						$project->mincost += $item->amount;
						$project->maxcost += $item->amount;
					}
					else {
						$project->maxcost += $item->amount;
					}
				}

				// retornos colectivos
				$project->social_rewards = Project\Reward::getAll($id, 'social');
				// retornos individuales
				$project->individual_rewards = Project\Reward::getAll($id, 'individual');

				// colaboraciones
				$project->supports = Project\Support::getAll($id);


				return $project;

			} catch(\PDOException $e) {
				echo $e->getMessage();
				return false;
			}
		}

        /**
         * Inserta un proyecto con los datos mínimos
         *
         * @param array $data
         * @return boolean
         */
        public function create($user, $node = 'goteo') {

            // cojemos el número de proyecto de este usuario
            $query = self::query("SELECT COUNT(id) as num FROM project WHERE owner = ?", array($user));
            $now = $query->fetchObject();
            $num = $now->num + 1;

            $values = array(
                ':id'   => md5($user.'-'.$num),
                ':name' => "Mi proyecto $num",
                ':status'   => 1,
                ':progress' => 0,
                ':owner' => $user,
                ':node' => $node,
                ':amount' => 0,
                ':created'  => date('Y-m-d')
                );

            $sql = "REPLACE INTO project (id, name, status, progress, owner, node, amount, created)
                 VALUES (:id, :name, :status, :progress, :owner, :node, :amount, :created)";
            try {
				self::query($sql, $values);

                $this->id = $values[':id'];
                $this->owner = $user;
                $this->node = $node;
                $this->status = 1;
                $this->progress = 0;

                // cargar los datos legales del usuario

                return $this->id;
            } catch (\PDOException $e) {
                echo "ERROR al crear un nuevo proyecto<br />$sql<br /><pre>" . print_r($values, 1) . "</pre>";
                return false;
            }
        }

        /*
         * Recupera los datos de contrato del anterior proyecto
		 * No es tan util como se pensaba...
         *
        public function lastContract() {
            $filters = array(
                'owner'=>$this->owner,
                'contract_nif'=>'IS NOT NULL',
                'id'=>"!{$this->id}"
                );
            $proj = self::getAll($filters, 'created DESC LIMIT 1');
            $this->contract_name = $proj[0]['contract_name'];
            $this->contract_surname = $proj[0]['contract_surname'];
            $this->contract_nif = $proj[0]['contract_nif'];
            $this->contract_email = $proj[0]['contract_email'];
            
        }
		 * 
		 */

        /**
         * actualiza en un proyecto pares de campo=>valor
         * @param array $data
         * @param array $errors
         */
        public function save (&$errors = array()) {

            // nif y telefono sin guinoes, espacios ni puntos
            $this->contract_nif = str_replace(array('_', '.', ' ', '-', ','), '', $this->contract_nif);
            $this->phone = str_replace(array('_', '.', ' ', '-', ','), '', $this->phone);

            $fields = array(
                'contract_name',
                'contract_surname',
                'contract_nif',
                'contract_email',
                'phone',
                'address',
                'zipcode',
                'location',
                'country',
                'name',
                'image',
                'description',
                'motivation',
                'about',
                'goal',
                'related',
                'keywords',
                'media',
                'currently',
                'project_location',
                'resource'
                );

            $set = '';
            $values = array();

            foreach ($fields as $field) {
                if (!empty($this->$field)) {
                    $set .= "$field = :$field, ";
                    $values[":$field"] = $this->$field;
                }
            }

			try {
				$set .= "updated = :updated";
				$values[':updated'] = date('Y-m-d');
				$values[':id'] = $this->id;

				$sql = "UPDATE project SET " . $set . " WHERE id = :id";
				$res = self::query($sql, $values);

			} catch(\PDOException $e) {
                $errors[] = Text::get('error sql guardar proyecto');
			}

        }

        /*
         *  Para validar los campos del proyecto
         * cualquier campo incorrecto lo guarda en badfields y en badmessages
         * luego se consulta para pintar el checkbox lateral o los errores
         * También cuenta la puntuación del 1 al 100 para el proyecto y lo guarda
         *
         * Hay que ver el perfil del usuario, tener un perfil decente también da puntos, no?
         *
         */
        public function validate (&$errors = array())
        {
            $score = 0;
            $max = 0; // el máximo que se puede conseguir

            // debe tener en cuenta los errores y quitar puntos por ellos

//              'contract_name',  //mandatory +1
            if (empty($this->contract_name)) {
                $errors['contract_name'] = Text::get('mandatory project field contract name');
                --$score;
            } else {
                ++$score;
            }
            ++$max;

//              'contract_surname',  //mandatory +1
            if (empty($this->contract_surname)) {
                $errors['contract_surname'] = Text::get('mandatory project field contract surname');
                --$score;
            } else {
                ++$score;
            }
            ++$max;

//              'contract_nif',  //mandatory  validation nif +1
            if (empty($this->contract_nif)) {
                $errors['contract_nif'] = Text::get('mandatory project field contract nif');
                --$score;
            } elseif (!Check::Nif($this->contract_nif)) {
                    $errors['contract_nif'] = Text::get('validate project value contract nif');
                    --$score;
                } else {
                    ++$score;
                }
            ++$max;

//              'contract_email',  //mandatory validation email +1
            if (empty($this->contract_email)) {
                $errors['contract_email'] = Text::get('mandatory project field contract email');
                --$score;
            } elseif (!Check::Mail($this->contract_email)) {
                    $errors['contract_email'] = Text::get('validate project value contract email');
                    --$score;
                } else {
                    ++$score;
                }
            ++$max;

//              'phone', // mandatory validation phone +1
            if (empty($this->phone)) {
                $errors['phone'] = Text::get('mandatory project field phone');
                --$score;
            } elseif (!Check::Phone($this->phone)) {
                    $errors['phone'] = Text::get('validate project value phone');
                    --$score;
                } else {
                    ++$score;
                }
            ++$max;

//              'address', // +1
            if (!empty($this->address)) {
                $errors['address'] = Text::get('mandatory project field address');
                --$score;
			} else {
                ++$score;
            }
            ++$max;

//              'zipcode', // +1
            if (empty($this->zipcode)) {
                $errors['zipcode'] = Text::get('mandatory project field zipcode');
                --$score;
			} else {
                ++$score;
            }
            ++$max;

//              'location', // mandatory  +1
            if (empty($this->location)) {
                $errors['location'] = Text::get('mandatory project field residence');
                --$score;
            } else {
                ++$score;
            }
            ++$max;

//              'country', // mandatory +1
            if (empty($this->country)) {
                $errors['country'] = Text::get('mandatory project field country');
                --$score;
			} else {
                ++$score;
            }
            ++$max;

//              'name', // mandatory +1
            if (empty($this->name)) {
                $errors['name'] = Text::get('mandatory project field name');
                --$score;
            } else {
                ++$score;
            }
            ++$max;

//              'image', // mandatory +5
            if (!empty($this->image)) {
                $errors['image'] = Text::get('mandatory project field image');
                $score -= 5;
			} else {
                $score += 5;
            }
            $max += 5;

//              'description', // mandatory +1 validation 150 words (+5 if so)
            if (empty($this->description)) {
                $errors['description'] = Text::get('mandatory project field description');
                --$score;
            } else {
                ++$score;
                if (!Check::Words($this->description, 150)) {
                    $errors['description'] = Text::get('validate project value description');
                    $score -= 5;
                } else {
                    $score += 5;
                }
            }
            $max += 6;

//              'motivation', // +1
            if (empty($this->motivation)) {
                $errors['motivation'] = Text::get('mandatory project field motivation');
                --$score;
            } else {
                ++$score;
            }
            ++$max;

//              'about', // +1
            if (empty($this->about)) {
                $errors['about'] = Text::get('mandatory project field about');
                --$score;
            } else {
                ++$score;
            }
            ++$max;

//              'goal', // +1
            if (empty($this->goal)) {
                $errors['goal'] = Text::get('mandatory project field goal');
                --$score;
            } else {
                ++$score;
            }
            ++$max;

//              'related', // +1
            if (!empty($this->related)) {
                $errors['related'] = Text::get('mandatory project field related');
                --$score;
            } else {
                ++$score;
            }
            ++$max;

//              'category', // mandatory +1
            if (empty($this->category)) {
                $errors['category'] = Text::get('mandatory project field category');
                --$score;
            } else {
                ++$score;
            }
            ++$max;

//              'media', // +5
            if (!empty($this->media)) {
                $score += 5;
            }
            $max += 5;

//              'keywords', // +1 * keyword until +5
            if (!empty($this->keywords)) {
                $keywords = explode(',', $this->keywords);
                $score += count($keywords) > 5 ? 5 : count($keywords);
            }
            $max += 5;
            
//              'currently', // +1 * value
            if (!empty($this->currently)) {
                $score += $this->currently;
            }
            ++$max;
            
//              'project_location', // mandatory +1
            if (empty($this->project_location)) {
                $errors['project_location'] = Text::get('mandatory project field location');
                --$score;
            }
            else {
                ++$score;
            }
            ++$max;

//              'costs', // mandatory at least 2 costs (with amount)+5 if so ;  validation dates
            if (count($this->costs) < 2) {
                $errors['ncost'] = Text::get('validation project min costs');
                $score -= 5;
            }
            else {
                $score += 5;
//              +1 * cost  until +5
                $score += count($this->costs) > 5 ? 5 : count($this->costs);
            }
            $max += 10;
//          +2 * cost with date from->until   until +10
            $got = 0;
            foreach($this->costs as $cost) {
                if (!empty($cost->from) && !empty($cost->until))  {
                    // @TODO validar si fecha desde es menor que hasta
                    $got += 2; // si es asi, sino -2
                }
            }
            $score += $got > 10 ? 10 : $got;
            $max += 10;

//          mandatory  max cost = min cost +40%   +5
            $costdif = $this->maxcost - $this->mincost;
            $maxdif = $this->mincost * 0.40;
            if ($costdif > $maxdif ) {
                $errors['total-costs'] = Text::get('validation project total cost');
                $score -= 5;
            }
            else {
                $score += 5;
            }
            $max += 5;

//              'resource', // +0

//              'rewards', // +2 * reward until + 10
            $score += count($this->social_rewards) > 5 ? 5 : count($this->social_rewards);
            $score += count($this->individual_rewards) > 5 ? 5 : count($this->individual_rewards);
            $max += 10;

//          +2 if any license selected
            foreach ($this->social_rewards as $social) {
                if (!empty($social->license)) {
                    $score += 2;
                    break;
                }
            }
            $max += 2;
//              'supports' // +0

            // total score
            if ($score < 0) {
                $progress = 0;
            } else {
                // rate over max
                $progress = 100 * $score / $max;
                $progress = round($progress, 0);
                if ($progress > 100) $progress = 100;
            }

            $this->fiuuu = "Obtenido $score sobre $max = $progress %";

            $sql = "UPDATE project SET progress = :progress WHERE id = :id";
            if (self::query($sql, array(':progress'=>$progress, ':id'=>$this->id))) {
                $this->progress = $progress;
            }
        }

        /*
         * Listo para revisión
         */
        public function ready() {
			try {
				$sql = "UPDATE project SET status = :status, updated = :updated WHERE id = :id";
				self::query($sql, array(':status'=>2, ':updated'=>date('Y-m-d'), ':id'=>$this->id));
				$this->rebase();
            } catch (\PDOException $e) {
                return false;
            }
        }

        /*
         * Para cambiar el id temporal a idealiza
         * solo si es md5
         */
        public function rebase() {
            if (preg_match('/^[A-Fa-f0-9]{32}$/',$this->id)) {
                // idealizar el nombre
                $newid = self::checkId(self::idealiza($this->name));
                if ($newid == false) return false;
                // actualizar las tablas relacionadas
                self::query("UPDATE project_category SET project = :newid WHERE project = :id", array(':newid'=>$newid, ':id'=>$this->id));
                self::query("UPDATE cost SET project = :newid WHERE project = :id", array(':newid'=>$newid, ':id'=>$this->id));
                self::query("UPDATE reward SET project = :newid WHERE project = :id", array(':newid'=>$newid, ':id'=>$this->id));
                self::query("UPDATE support SET project = :newid WHERE project = :id", array(':newid'=>$newid, ':id'=>$this->id));
                // actualizar el registro
                self::query("UPDATE project SET id = :newid WHERE id = :id", array(':newid'=>$newid, ':id'=>$this->id));
				$this->id = $newid;
            }

            return true;
        }

        /*
         *  Para verificar id única
         */
        public static function checkId($id, $num = 1) {
            if ($query = self::query("SELECT id FROM project WHERE id = :id", array(':id'=>$id))) {
                $exist = $query->fetchObject();
                // si  ya existe, cambiar las últimas letras por un número
                
                if (!empty($exist->id)) {
                    $sufix = (string) $num;
                    if ((strlen($id)+strlen($sufix)) > 49) 
                        $id = substr($id, 0, (strlen($id) - strlen($sufix))) . $sufix;
                    else 
                        $id = $id . $sufix;
                    $num++;
                    $id = self::checkId($id, $num);
                }
                return $id;
            }
            else {
                echo "Fallo rebase en $id, $num <br />";
                return false;
            }
        }


        /*
         * Lista de proyectos de un usuario
         */
        public static function ofmine($owner)
        {
            $filters = array('owner'=>$owner);
            $projects = self::getAll($filters, 'name ASC');
            return $projects;
        }




        /**
         * Saca una lista de proyectos
         *
         * @param array $filters
         * @param string $order
         * @return array or false
         */
        public static function getAll($filters = array(), $order = '') {
            $vals = array();
            $filter = "";
            foreach ($filters as $field=>$value) {
                $filter .= $filter == "" ? " WHERE" : " AND";
                if (strtolower(substr($value, 0, 2)) == 'is') {
                    $filter .= " $field " . $value;
                }
                elseif (substr($value, 0, 1) == '!') {
                    $filter .= " $field != :$field";
                    $vals[":$field"] = substr($value, 1);
                }
                else {
                    $filter .= " $field = :$field";
                    $vals[":$field"] = $value;
                }

            }

            if (!empty ($order)) {
                $order = " ORDER BY $order";
            }

			try {
				$query = self::query("SELECT * FROM project" . $filter . $order, $vals);
                return $query->fetchAll(\PDO::FETCH_CLASS, __CLASS__);
            } catch (\PDOException $e) {
				echo $e->getMessage();
                return false;
            }
        }

        /*
         * Estados de desarrollo del propyecto
         */
        public static function currentStatus () {
            return array(
                1=>'Inicial',
                2=>'Medio',
                3=>'Avanzado',
                4=>'Finalizado');
        }

        /*
         * Estados de publicación de un proyecto
         */
        public static function status () {
            return array(
                1=>'Editándose',
                2=>'Pendiente valoración',
                3=>'En campaña',
                4=>'Financiado',
                5=>'Caducado',
                6=>'Retorno cumplido');
        }

        /**
         * Para pintar el checked en el campo
         * @param string $field
         * @return boolean
         */
        public function check($field)
        {
            // si el campo que recibimos está en badfiels, tienen que revisarlo.
            if (empty($this->badfields[$field])) {
                return true;
            } else {
                echo $this->badfields[$field];
                return false;
            }
        }



    }

}