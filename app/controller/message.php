<?php

namespace Goteo\Controller {

    use Goteo\Core\ACL,
        Goteo\Core\Error,
        Goteo\Core\Redirection,
        Goteo\Core\View,
        Goteo\Model,
		Goteo\Library\Feed,
        Goteo\Library\Mail,
        Goteo\Library\Template,
        Goteo\Library\Text;

    class Message extends \Goteo\Core\Controller {

        public function index ($project = null) {
            if (empty($project))
                throw new Redirection('/discover', Redirection::PERMANENT);

			if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['message'])) {

                $projectData = Model\Project::getMini($project);

                if ($projectData->status < 3) {
                    \Goteo\Library\Message::Error(Text::get('project-messages-closed'));
                    throw new Redirection("/project/{$project}");
                }

                $message = new Model\Message(array(
                    'user' => $_SESSION['user']->id,
                    'project' => $project,
                    'thread' => $_POST['thread'],
                    'message' => $_POST['message']
                ));

                if ($message->save($errors)) {
                    $support = Model\Message::isSupport($_POST['thread']);
                    
                    // Evento Feed
                    $log = new Feed();
                    $log->setTarget($projectData->id);
                    if (empty($_POST['thread'])) {
                        // nuevo hilo
                        $log_html = \vsprintf('%s ha creado un tema en %s del proyecto %s', array(
                            Feed::item('user', $_SESSION['user']->name, $_SESSION['user']->id),
                            Feed::item('message', Text::get('project-menu-messages'), $projectData->id.'/messages#message'.$message->id),
                            Feed::item('project', $projectData->name, $projectData->id)
                        ));
                    } else {
                        // respuesta
                        // si una respuesta a un mensaje de colaboraicón
                        if (!empty($support)) {
                            $log_html = \vsprintf('Nueva colaboración de %s con %s en el proyecto %s', array(
                                Feed::item('user', $_SESSION['user']->name, $_SESSION['user']->id),
                                Feed::item('message', $support, $projectData->id.'/messages#message'.$_POST['thread']),
                                Feed::item('project', $projectData->name, $projectData->id)
                            ));
                        } else { // es una respuesta a un hilo normal
                            $log_html = \vsprintf('%s ha respondido en %s del proyecto %s', array(
                                Feed::item('user', $_SESSION['user']->name, $_SESSION['user']->id),
                                Feed::item('message', Text::get('project-menu-messages'), $projectData->id.'/messages#message'.$message->id),
                                Feed::item('project', $projectData->name, $projectData->id)
                            ));
                        }
                    }
                    $log->populate('usuario escribe mensaje/respuesta en Mensajes del proyecto', '/admin/projects', $log_html);
                    $log->doAdmin('user');

                    // Evento público
                    if (empty($_POST['thread'])) {
                        $log_html = Text::html('feed-messages-new_thread',
                                            Feed::item('message', Text::get('project-menu-messages'), $projectData->id.'/messages#message'.$message->id),
                                            Feed::item('project', $projectData->name, $projectData->id)
                                            );
                    } else {
                        // si una respuesta a un mensaje de colaboraicón
                        if (!empty($support)) {
                            $log_html = Text::html('feed-message_support-response',
                                            Feed::item('message', $support, $projectData->id.'/messages#message'.$_POST['thread']),
                                            Feed::item('project', $projectData->name, $projectData->id)
                                        );
                        } else { // es una respuesta a un hilo normal
                            $log_html = Text::html('feed-messages-response',
                                            Feed::item('message', Text::get('project-menu-messages'), $projectData->id.'/messages#message'.$message->id),
                                            Feed::item('project', $projectData->name, $projectData->id)
                                        );
                        }
                    }
                    $log->populate($_SESSION['user']->name, '/user/profile/'.$_SESSION['user']->id, $log_html, $_SESSION['user']->avatar->id);
                    $log->doPublic('community');
                    unset($log);

                    if (!empty($_POST['thread'])) {
                        // aqui el owner es el autor del mensaje thread
                        $thread = Model\Message::get($_POST['thread']);

                        // Si no tiene estas notiicaciones bloqueadas en sus preferencias
                        $sql = "
                            SELECT
                              user_prefer.threads as spam,
                              user_prefer.comlang as lang
                            FROM user_prefer
                            WHERE user = :user
                            ";
                        $query = Model\Project::query($sql, array(':user' => $thread->user->id));
                        $prefer = $query->fetchObject();
                        if (!empty($thread->user->name) && !$prefer->spam) {
                            // Mail al autor del thread
                            $comlang = !empty($prefer->lang) ? $prefer->lang : $thread->user->lang;
                            // Obtenemos la plantilla para asunto y contenido
                            $template = Template::get(12, $comlang);

                            // Sustituimos los datos
                            $subject = str_replace('%PROJECTNAME%', $projectData->name, $template->title);

                            $response_url = SITE_URL . '/user/profile/' . $_SESSION['user']->id . '/message';
                            $project_url = SITE_URL . '/project/' . $projectData->id . '/messages#message'.$message->id;

                            $search  = array('%MESSAGE%', '%OWNERNAME%', '%USERNAME%', '%PROJECTNAME%', '%PROJECTURL%', '%RESPONSEURL%');
                            $replace = array($_POST['message'], $thread->user->name, $_SESSION['user']->name, $projectData->name, $project_url, $response_url);
                            $content = \str_replace($search, $replace, $template->text);

                            $mailHandler = new Mail();

                            $mailHandler->to = $thread->user->email;
                            $mailHandler->toName = $thread->user->name;
                            $mailHandler->subject = $subject;
                            $mailHandler->content = $content;
                            $mailHandler->html = true;
                            $mailHandler->template = $template->id;
                            $mailHandler->send($errors);

                            unset($mailHandler);
                        }
                    } else {
                        // mensaje al autor del proyecto

                        //  idioma de preferencia
                        $prefer = Model\User::getPreferences($projectData->user->id);
                        $comlang = !empty($prefer->comlang) ? $prefer->comlang : $projectData->user->lang;

                        // Obtenemos la plantilla para asunto y contenido
                        $template = Template::get(30, $comlang);

                        // Sustituimos los datos
                        $subject = str_replace('%PROJECTNAME%', $projectData->name, $template->title);

                        $response_url = SITE_URL . '/user/profile/' . $_SESSION['user']->id . '/message';
                        $project_url = SITE_URL . '/project/' . $projectData->id . '/messages#message'.$message->id;

                        $search  = array('%MESSAGE%', '%OWNERNAME%', '%USERNAME%', '%PROJECTNAME%', '%PROJECTURL%', '%RESPONSEURL%');
                        $replace = array($_POST['message'], $projectData->user->name, $_SESSION['user']->name, $projectData->name, $project_url, $response_url);
                        $content = \str_replace($search, $replace, $template->text);

                        $mailHandler = new Mail();

                        $mailHandler->to = $projectData->user->email;
                        $mailHandler->toName = $projectData->user->name;
                        $mailHandler->subject = $subject;
                        $mailHandler->content = $content;
                        $mailHandler->html = true;
                        $mailHandler->template = $template->id;
                        $mailHandler->send($errors);

                        unset($mailHandler);
                    }


                }
			}

            throw new Redirection("/project/{$project}/messages#message".$message->id, Redirection::TEMPORARY);
        }

        public function edit ($id, $project) {

            if (isset($_POST['message'])) {
                $message = Model\Message::get($id);
                $message->user = $message->user->id;
                $message->message = ($_POST['message']);

                $message->save();
            }

            throw new Redirection("/project/{$project}/messages", Redirection::TEMPORARY);
        }

        public function delete ($id, $project) {

            Model\Message::get($id)->delete();

            throw new Redirection("/project/{$project}/messages", Redirection::TEMPORARY);
        }

        /*
         * Este metodo envia un mensaje interno
         */
        public function direct ($project = null) {
            if (empty($project))
                throw new Redirection('/discover', Redirection::PERMANENT);

            if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['message'])) {

                // verificamos token
                if (!isset($_POST['msg_token']) || $_POST['msg_token']!=$_SESSION['msg_token']) {
//                    throw new Error(Error::BAD_REQUEST);
                    header("HTTP/1.1 418");
                    die('Temporalmente no disponible');
                }

                // sacamos el mail del responsable del proyecto
                $project = Model\Project::getMini($project);
                $ownerData = Model\User::getMini($project->owner);

                $msg_content = \nl2br(\strip_tags($_POST['message']));

                //  idioma de preferencia
                $prefer = Model\User::getPreferences($ownerData->id);
                $comlang = !empty($prefer->comlang) ? $prefer->comlang : $ownerData->lang;

                // Obtenemos la plantilla para asunto y contenido
                $template = Template::get(3, $comlang);

                // Sustituimos los datos
                // En el asunto: %PROJECTNAME% por $project->name
                $subject = str_replace('%PROJECTNAME%', $project->name, $template->title);

                $response_url = SITE_URL . '/user/profile/' . $_SESSION['user']->id . '/message';

                // En el contenido:  nombre del autor -> %OWNERNAME% por $project->contract_name
                // el mensaje que ha escrito el productor -> %MESSAGE% por $msg_content
                // nombre del usuario que ha aportado -> %USERNAME% por $_SESSION['user']->name
                // nombre del proyecto -> %PROJECTNAME% por $project->name
                // url de la plataforma -> %SITEURL% por SITE_URL
                $search  = array('%MESSAGE%', '%OWNERNAME%', '%USERNAME%', '%PROJECTNAME%', '%SITEURL%', '%RESPONSEURL%');
                $replace = array($msg_content, $ownerData->name, $_SESSION['user']->name, $project->name, SITE_URL, $response_url);
                $content = \str_replace($search, $replace, $template->text);
                
                $mailHandler = new Mail();

                $mailHandler->to = $ownerData->email;
                $mailHandler->toName = $ownerData->name;
                $mailHandler->subject = $subject;
                $mailHandler->content = $content;
                $mailHandler->html = true;
                $mailHandler->template = $template->id;
                if ($mailHandler->send($errors)) {
                    // ok
                    \Goteo\Library\Message::Info(Text::get('regular-message_success'));
                } else {
                    \Goteo\Library\Message::Info(Text::get('regular-message_fail') . '<br />' . implode(', ', $errors));
                }

                unset($mailHandler);
			}

            throw new Redirection("/project/{$project->id}/messages", Redirection::TEMPORARY);
        }

        /*
         * Este metodo envia un mensaje personal
         */
        public function personal ($user = null) {
            // verificacion de que esté autorizasdo a enviar mensaje
            if (!isset($_SESSION['message_autorized']) || $_SESSION['message_autorized'] !== true) {
                \Goteo\Library\Message::Info('Temporalmente no disponible. Disculpen las molestias');
                throw new Redirection('/');
            } else {
                // y quitamos esta autorización
                unset($_SESSION['message_autorized']);
            }

            if (empty($user))
                throw new Redirection('/community', Redirection::PERMANENT);

            if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['message'])) {

                // verificamos token
                if (!isset($_POST['msg_token']) || $_POST['msg_token']!=$_SESSION['msg_token']) {
                    header("HTTP/1.1 418");
                    die('Temporalmente no disponible');
                }

                // sacamos el mail del responsable del proyecto
                $user = Model\User::get($user);

                if (!$user instanceof Model\User) {
                    throw new Redirection('/', Redirection::TEMPORARY);
                }

                $msg_content = \nl2br(\strip_tags($_POST['message']));


                //  idioma de preferencia
                $prefer = Model\User::getPreferences($user->id);
                $comlang = !empty($prefer->comlang) ? $prefer->comlang : $user->lang;

                // Obtenemos la plantilla para asunto y contenido
                $template = Template::get(4, $comlang);

                // Sustituimos los datos
                if (isset($_POST['subject']) && !empty($_POST['subject'])) {
                    $subject = $_POST['subject'];
                } else {
                    // En el asunto por defecto: %USERNAME% por $_SESSION['user']->name
                    $subject = str_replace('%USERNAME%', $_SESSION['user']->name, $template->title);
                }

                $remite = $_SESSION['user']->name . ' ' . Text::get('regular-from') . ' ';
                $remite .= (NODE_ID != GOTEO_NODE) ? NODE_NAME : GOTEO_MAIL_NAME;
                
                $response_url = SITE_URL . '/user/profile/' . $_SESSION['user']->id . '/message';
                $profile_url = SITE_URL."/user/profile/{$user->id}/sharemates";
                // En el contenido:  nombre del destinatario -> %TONAME% por $user->name
                // el mensaje que ha escrito el usuario -> %MESSAGE% por $msg_content
                // nombre del usuario -> %USERNAME% por $_SESSION['user']->name
                // url del perfil -> %PROFILEURL% por ".SITE_URL."/user/profile/{$user->id}/sharemates"
                $search  = array('%MESSAGE%','%TONAME%',  '%USERNAME%', '%PROFILEURL%', '%RESPONSEURL%');
                $replace = array($msg_content, $user->name, $_SESSION['user']->name, $profile_url, $response_url);
                $content = \str_replace($search, $replace, $template->text);

                $mailHandler = new Mail();
                $mailHandler->fromName = $remite;
                $mailHandler->to = $user->email;
                $mailHandler->toName = $user->name;
                // blind copy a goteo desactivado durante las verificaciones
//                $mailHandler->bcc = 'comunicaciones@goteo.org';
                $mailHandler->subject = $subject;
                $mailHandler->content = $content;
                $mailHandler->html = true;
                $mailHandler->template = $template->id;
                if ($mailHandler->send($errors)) {
                    // ok
                    \Goteo\Library\Message::Info(Text::get('regular-message_success'));
                } else {
                    \Goteo\Library\Message::Info(Text::get('regular-message_fail') . '<br />' . implode(', ', $errors));
                }

                unset($mailHandler);
			}

            throw new Redirection("/user/profile/{$user->id}", Redirection::TEMPORARY);
        }

        /*
         * Metodo para publicar un comentario en un post
         */
        public function post ($post, $project = null) {

			if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['message'])) {

                 //eliminamos etiquetas script, iframe, embed y form.

                $comment = new Model\Blog\Post\Comment(array(
                    'user' => $_SESSION['user']->id,
                    'post' => $post,
                    'date' => date('Y-m-d H:i:s'),
                    'text' => $_POST['message']
                ));

                if ($comment->save($errors)) {
                    // a ver los datos del post
                    $postData = Model\Blog\Post::get($post);


                    // si es entrada de proyecto
                    if (!empty($project)) {

                        $projectData = Model\Project::getMini($project);

                        // Evento Feed
                        $log = new Feed();
                        $log->setTarget($projectData->id);
                        $log_html = \vsprintf('%s ha escrito un %s en la entrada "%s" en las %s del proyecto %s', array(
                            Feed::item('user', $_SESSION['user']->name, $_SESSION['user']->id),
                            Feed::item('message', 'Comentario'),
                            Feed::item('update-comment', $postData->title, $projectData->id.'/updates/'.$postData->id.'#comment'.$comment->id),
                            Feed::item('update-comment', 'Novedades', $projectData->id.'/updates/'),
                            Feed::item('project', $projectData->name, $projectData->id)
                        ));
                        $log->populate('usuario escribe comentario en blog/novedades', '/admin/projects', $log_html);
                        $log->doAdmin('user');

                        // Evento público
                        $log_html = Text::html('feed-updates-comment',
                            Feed::item('update-comment', $postData->title, $projectData->id.'/updates/'.$postData->id.'#comment'.$comment->id),
                            Feed::item('update-comment', 'Novedades', $projectData->id.'/updates/'),
                            Feed::item('project', $projectData->name, $projectData->id)
                        );
                        $log->populate($_SESSION['user']->name, '/user/profile/'.$_SESSION['user']->id, $log_html, $_SESSION['user']->avatar->id);
                        $log->doPublic('community');
                        unset($log);

                        //Notificación al autor del proyecto

                        //  idioma de preferencia
                        $prefer = Model\User::getPreferences($projectData->user->id);
                        $comlang = !empty($prefer->comlang) ? $prefer->comlang : $projectData->user->lang;

                        // Obtenemos la plantilla para asunto y contenido
                        $template = Template::get(31, $comlang);

                        // Sustituimos los datos
                        $subject = str_replace('%PROJECTNAME%', $projectData->name, $template->title);

                        $response_url = SITE_URL . '/user/profile/' . $_SESSION['user']->id . '/message';
                        $project_url = SITE_URL . '/project/' . $projectData->id . '/updates/'.$postData->id.'#comment'.$comment->id;

                        $search  = array('%MESSAGE%', '%OWNERNAME%', '%USERNAME%', '%PROJECTNAME%', '%PROJECTURL%', '%RESPONSEURL%');
                        $replace = array($_POST['message'], $projectData->user->name, $_SESSION['user']->name, $projectData->name, $project_url, $response_url);
                        $content = \str_replace($search, $replace, $template->text);

                        // que no pete si no puede enviar el mail al autor
                        try {
                            $mailHandler = new Mail();

                            $mailHandler->to = $projectData->user->email;
                            $mailHandler->toName = $projectData->user->name;
                            $mailHandler->subject = $subject;
                            $mailHandler->content = $content;
                            $mailHandler->html = true;
                            $mailHandler->template = $template->id;
                            $mailHandler->send($errors);

                            unset($mailHandler);
                        } catch (Exception $e) {
                            @mail(\GOTEO_FAIL_MAIL, 'FAIL '. __FUNCTION__ .' en ' . SITE_URL,
                                'Ha fallado a enviar mail a autor '. __FUNCTION__ .' en ' . SITE_URL.' a las ' . date ('H:i:s') . ' Objeto '. \trace($mailHandler));
                        }

                    } else {

                        // Evento Feed
                        $log = new Feed();
                        $log->setTarget('goteo', 'blog');
                        $log_html = \vsprintf('%s ha escrito un %s en la entrada "%s" del blog de %s', array(
                            Feed::item('user', $_SESSION['user']->name, $_SESSION['user']->id),
                            Feed::item('message', 'Comentario'),
                            Feed::item('blog', $postData->title, $postData->id.'#comment'.$comment->id),
                            Feed::item('blog', 'Goteo', '/')
                        ));
                        $log->populate('usuario escribe comentario en blog/novedades', '/admin/projects', $log_html);
                        $log->doAdmin('user');

                        // Evento público
                        $log_html = Text::html('feed-blog-comment',
                            Feed::item('blog', $postData->title, $postData->id.'#comment'.$comment->id),
                            Feed::item('blog', 'Goteo', '/')
                        );
                        $log->populate($_SESSION['user']->name, '/user/profile/'.$_SESSION['user']->id, $log_html, $_SESSION['user']->avatar->id);
                        $log->doPublic('community');
                        unset($log);

                    }

                } else {
                    // error
                    @mail(\GOTEO_FAIL_MAIL, 'FAIL '. __FUNCTION__ .' en ' . SITE_URL,
                        'No ha grabado el comentario en post. '. __FUNCTION__ .' en ' . SITE_URL.' a las ' . date ('H:i:s') . ' Usuario '. $_SESSION['user']->id . ' Errores: '.implode('<br />', $errors));

                }
			}

            if (!empty($project)) {
                throw new Redirection("/project/{$project}/updates/{$post}#comment".$comment->id, Redirection::TEMPORARY);
            } else {
                throw new Redirection("/blog/{$post}#comment".$comment->id, Redirection::TEMPORARY);
            }
        }

    }

}