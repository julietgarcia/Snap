<?php

/**
* Handles ticket CRUD and other methods.
*
* @package		SAAV
* @subpackage	Controllers
* @author		Mario Cuba <mario@mariocuba.net>
*/
class Ticket_Controller extends Base_Controller {

	public $restful = true;

	/**
	* Shows the add ticket form
	*
	* @access	public
	*/
	public function get_add() {
		// display the form
		return View::make('ticket/add')->with('title', 'Nueva Consulta');
	}

	/**
	* Shows detailed information about a ticket
	*
	* @param	
	* @access	public
	*/
	public function get_view($ticket) {
		$ticket		= Ticket::find($ticket);
		$messages	= $ticket->messages()->get();

		// ticket details
		$reporter						= User::find($ticket->reported_by);
		$reporter->fullname = $reporter->firstname . ' ' . $reporter->lastname;
		$department					= Department::find($ticket->department);
		$assigned						= User::find($ticket->assigned_to);

		// markdown enabled view
		Load::library('markdown/markdown');

		// load markdown editor
		Asset::add('markdown-converter', 'js/markdown/Markdown.Converter.js', 'jquery');
		Asset::add('markdown-sanitizer', 'js/markdown/Markdown.Sanitizer.js', array('jquery', 'markdown-converter'));
		Asset::add('markdown-editor', 'js/markdown/Markdown.Editor.js', array('jquery', 'markdown-converter', 'markdown-sanitizer'));

		return View::make('ticket/view')
		->with('ticket', $ticket)
		->with('messages', $messages)
		->with('reporter', $reporter)
		->with('assigned', $assigned)
		->with('department', $department)
		->with('title', 'Consulta #' . $ticket->id . ': ' . $ticket->subject);
	}

	/**
	* Adds a new ticket
	*
	* @access	public
	*/
	public function post_add() {
		$input	= array(
			'department'	=> Input::get('department'),
			'subject'			=> Input::get('subject'),
			'content'			=> Input::get('content')
		); 

		// only support and admins can set an assigned person
		if (Input::get('assign')) {
			$input['assigned_to'] = Input::get('assign');
		}

		$rules = array(
			'department'	=> 'required',
			'subject'			=> 'required',
			'content'			=> 'required'
		);

		$validation = Validator::make($input, $rules);

		if ($validation->fails()) {
			return Redirect::to('ticket/add')->with('notification', 'form_required');
		}

		// validation passed, prepare data to be added to database
		$ticket = array(
			'subject'			=> $input['subject'],
			'content'			=> $input['content'],
			'department'	=> $input['department'],
			'reported_by'	=> Session::get('id'),
		);

		if (isset($input['assigned_to'])) {
			$ticket['assigned_to']	= $input['assigned_to'];
		}

		// notify only the assigned person or the whole department
		if (isset($input['assigned_to'])) {
			$members	= User::where_id($input['assigned_to'])->get(array('firstname', 'lastname', 'email'));
		} else {
			$members	= Department::find($input['department'])->user()->where_deleted('0')->get('firstname', 'lastname', 'email');
		}
		
		// get the email addresses of people being notified
		foreach ($members as $member) {
			$bcc[$member->email] = $member->firstname . ' ' . $member->lastname;
		}

		// save it to the database
		$ticket		= Ticket::create($ticket);
		$reporter	= User::find(Session::get('id'));

		// create an email for the assigned person
		if (isset($input['assigned_to'])) {
			$subject	= 'Asignación de Consulta #' . $ticket->id . ': ' . $input['subject'];
			$view			= 'messages.ticket.assigned'; 

		// or for the whole department
		} else {
			$subject	= 'Consulta #' . $ticket->id . ': ' . $input['subject'];
			$view			= 'messages.ticket.created';
		}

		// prepare the data for the view
		$from				=& $reporter;												// needed
		$content		= $ticket->content;								// needed
	
		$body	= View::make($view)
		->with('from', $from)
		->with('content', $content)
		->with('ticket', $ticket);

		// send the mail
		$mailer		= IoC::resolve('mailer');
		$message	= Swift_Message::newInstance($subject)
		->setFrom(array('soporte@ingenium-dv.com' => 'Soporte'))	// @TODO: take from settings
		->setBcc($bcc)
		->setBody($body, 'text/html')
		->addPart($input['content'], 'text/plain');

		// send the email
		$sent = $mailer->send($message);

		// all good
		return View::make('ticket.success')->with('ticket', $ticket)->with('title', '¡Consulta creada!');
	}

	/**
	* Adds a new message to a ticket
	*
	* @param	int		- the ticket id
	* @access	public
	*/
	public function post_update($ticket) {
		$data = array(
			'user_id'	=> Session::get('id'),
			'content'	=> Input::get('content')
		);
		
		// save the status of the update
		$message	= Message::add($ticket, $data);
		$redirect	= Redirect::to('ticket/' . $ticket);
		$ticket		= Ticket::find($ticket);
		$status		= Input::get('status');
		$department = Input::get('department');
		$assign_to	= Input::get('assign');

		// set a new department if it was changed
		if (!empty($department)) {	
			$ticket->department = $department;
		}

		if (!empty($status)) {
			$ticket->status = $status;
		}

		if ($message === 'validation_failed') {
			return $redirect->with('notification', 'form_required');

		// database error — this should NEVER happen
		} elseif ($message === false) {
			return $redirect->with('notification', 'message_add_failed');
		}

		/**
		* now that the message is added, we need to make sure who
		* are we going to notify
		*
		* check:
		*
		* 1. if the person replying is who reported it
		* 2. if the person replying is tech support
		* 3. if there's a new assignment
		*/

		// who replied to the ticket
		$replier						= User::find($ticket->reported_by);
		$replier->fullname	= $replier->firstname . ' ' . $replier->lastname;

		/**
		* c: person who replyed is the person who reported it
		* d: notify the department if there's noone assigned, or the person assigned
		*/
		if (Session::get('id') == $ticket->reported_by) {
			// who should we send the notification?
			// if assigned to someone, send it to that person — if not, send it to the whole department
			if (!empty($ticket->assigned_to)) {
				$assigned = User::find($ticket->assigned_to);
				$bcc[$assigned->email] = $assigned->firstname . ' ' . $assigned->lastname;
			} else {
				$members = Department::find($ticket->department)->users()->where_deleted('0')->get('firstname', 'lastname', 'email');
				foreach($members as $member) {
					$bcc[$member->email] = $member->firstname . ' ' . $member->lastname;
				}
			}
		}

		/**
		* c: person who replyed is from tech support
		* d: notify only the reporter
		*/
		elseif (Session::get('id') != $ticket->reported_by) {
			$reporter								= User::find($ticket->reported_by);
			$bcc[$reporter->email]	= $reporter->firstname . ' ' . $reporter->lastname;
		}

		// create the message
		Load::library('markdown/markdown');

		$body = View::make('messages.ticket.updated')
		->with('ticket', $ticket)
		->with('replier', $replier)
		->with('content', Markdown($data['content']));

		// send the message
		$mailer		= IoC::resolve('mailer');
		$message	= Swift_Message::newInstance($replier->fullname . ' ha actualizado la Consulta #' . $ticket->id . ': ' . $ticket->subject)
		->setFrom(array('soporte@ingenium-dv.com' => 'Soporte'))	// @TODO: take from settings
		->setBcc($bcc)
		->setBody($body, 'text/html')
		->addPart($data['content'], 'text/plain');

		// send this message
		$sent = $mailer->send($message);

		/**
		* c: there's a new assignment
		* d: notify, individually, that new person
		*/	
		if (!empty($assign_to)) {
			// reset the recipents
			$bcc = array();

			$assigned = User::find($assign_to);
			$bcc[$assigned->email] = $assigned->firstname . ' ' . $assigned->lastname;

			// create the message
			$body = View::make('messages.ticket.assigned')
			->with('ticket', $ticket)
			->with('reporter', $replier)
			->with('content', Markdown($data['content']));

			$message = Swift_Message::newInstance($replier->fullname . ' le ha asignado una la consulta #' . $ticket->id . ': ' . $ticket->subject)
			->setFrom(array('soporte@ingenium-dv.com' => 'Soporte'))	// @TODO: take from settings
			->setBcc($bcc)
			->setBody($body, 'text/html')
			->addPart($data['content'], 'text/plain');

			// send this message
			$sent = $mailer->send($message);

			// and update the assigned person
			$ticket->assigned_to = $assign_to;
		}

		// save changes
		$ticket->save();

		return $redirect->with('notification', 'message_add_success');
	}

	/**
	* Changes the ticket status
	*
	* @param	int		- the ticket id
	* @access	public
	*/
	public function put_status($ticket) {
		$redirect		= Redirect::to('ticket/' . $ticket);
		$ticket			= Ticket::find($ticket);
		$ticket->status = Input::get('status');
		$ticket->save();

		return $redirect->with('notification', 'ticket_status_changed');
	}

}