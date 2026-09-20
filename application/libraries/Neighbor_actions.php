<?php
defined('BASEPATH') or exit('No direct script access allowed');

// The three neighbour-link actions that Nodes_API and TourStops_API both
// expose, defined once. They differ only in the name of the request field
// that identifies the owner ("node_id" / "stop_id") and in which model
// they call, so a controller using this supplies just those two hooks:
//
//   protected function neighborOwnerField()  — e.g. 'node_id'
//   protected function neighborModel()       — e.g. $this->Nodes_Model
//
// The actions stay public methods of the controller that uses the trait,
// so URLs (POST /Nodes_API/addNeighbor and so on) are unchanged.
trait Neighbor_actions
{
    abstract protected function neighborOwnerField();

    abstract protected function neighborModel();

    // POST .../addNeighbor — admin only.
    // Body: <owner>, neighbor_id, yaw, pitch, reverse_yaw, reverse_pitch
    // Writes both directions of the link in one call, which is why both
    // angles are required.
    public function addNeighbor()
    {
        $this->requireAdmin();

        $owner = $this->neighborOwnerField();
        $data = $this->getInput();
        Api_input::requirePresent($data, array($owner, 'neighbor_id', 'yaw', 'pitch', 'reverse_yaw', 'reverse_pitch'));

        $this->neighborModel()->addNeighbor(
            $data[$owner],
            $data['neighbor_id'],
            $data['yaw'],
            $data['pitch'],
            $data['reverse_yaw'],
            $data['reverse_pitch']
        );

        return Api_response::ok();
    }

    // POST .../removeNeighbor — admin only.
    // Body: <owner>, neighbor_id — removes both directions.
    public function removeNeighbor()
    {
        $this->requireAdmin();

        $owner = $this->neighborOwnerField();
        $data = $this->getInput();
        Api_input::requireFilled($data, array($owner, 'neighbor_id'), "{$owner} and neighbor_id are both required.");

        $this->neighborModel()->removeNeighbor($data[$owner], $data['neighbor_id']);
        return Api_response::ok();
    }

    // PATCH .../updateNeighborAngle — admin only.
    // Body: <owner>, neighbor_id, yaw, pitch — updates ONE direction's
    // angle only, matching setHotspot()'s real semantics. See
    // Neighbor_links::setAngle for why addNeighbor() alone can't do this.
    public function updateNeighborAngle()
    {
        $this->requireAdmin();

        $owner = $this->neighborOwnerField();
        $data = $this->getInput();
        Api_input::requirePresent($data, array($owner, 'neighbor_id', 'yaw', 'pitch'));

        $this->neighborModel()->updateNeighborAngle(
            $data[$owner],
            $data['neighbor_id'],
            $data['yaw'],
            $data['pitch']
        );
        return Api_response::ok();
    }
}
